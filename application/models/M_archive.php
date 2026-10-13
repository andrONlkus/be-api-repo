<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Model untuk API arsip umum. Semua query memakai Query Builder / binding.
 */
class M_archive extends CI_Model {

    // ---------------------------------------------------------------
    // Master data
    // ---------------------------------------------------------------

    function get_category($id_category)
    {
        return $this->db->select('id_category, code_category, code_full, name_category, level, id_parent, doc_status, doc_life')
            ->where('id_category', $id_category)
            ->where('status_category', 'active')
            ->get('categories')->row();
    }

    function get_categories($level = NULL, $parent = NULL, $q = NULL)
    {
        $this->db->select('id_category, code_full, name_category, level, id_parent, doc_status, doc_life')
            ->where('status_category', 'active');
        if ($level !== NULL)  $this->db->where('level', $level);
        if ($parent !== NULL) $this->db->where('id_parent', $parent);
        if ($q !== NULL && $q !== '') {
            $this->db->group_start()->like('name_category', $q)->or_like('code_full', $q)->group_end();
        }
        return $this->db->order_by('code_full', 'ASC')->get('categories')->result_array();
    }

    function get_departement($id_departement)
    {
        return $this->db->select('id_departement, nama_departement, upload_limit')
            ->where('id_departement', $id_departement)
            ->where('status_departement', 'active')
            ->get('departement')->row();
    }

    function get_departements()
    {
        return $this->db->select('id_departement, nama_departement, upload_limit')
            ->where('status_departement', 'active')
            ->order_by('nama_departement', 'ASC')
            ->get('departement')->result_array();
    }

    function get_tipefile($id_tipefile)
    {
        return $this->db->select('id_tipefile, tipefile')
            ->where('id_tipefile', $id_tipefile)
            ->where('status_tipefile', 'active')
            ->get('tipefile')->row();
    }

    function get_tipefiles()
    {
        return $this->db->select('id_tipefile, tipefile')
            ->where('status_tipefile', 'active')
            ->order_by('tipefile', 'ASC')
            ->get('tipefile')->result_array();
    }

    function user_exists($idusers)
    {
        return $this->db->where('idusers', $idusers)->where('active', 'Y')
            ->count_all_results('users') > 0;
    }

    /**
     * Cek hak akses departemen ke kategori, termasuk akses yang diberikan
     * di kategori induk. Nilai access bertingkat: 1 = view, 2 = download, 3 = upload/edit.
     */
    function has_category_access($id_category, $id_dept, $min_access = 3)
    {
        $ids = [];
        $id = (int) $id_category;
        while ($id > 0 && !in_array($id, $ids, TRUE) && count($ids) < 10) {
            $ids[] = $id;
            $row = $this->db->select('id_parent')->where('id_category', $id)->get('categories')->row();
            $id = $row ? (int) $row->id_parent : 0;
        }

        return $this->db->where('id_dept', $id_dept)
            ->where_in('id_category', $ids)
            ->where('CAST(access AS UNSIGNED) >=', (int) $min_access, FALSE)
            ->count_all_results('category_acc') > 0;
    }

    // ---------------------------------------------------------------
    // Kode arsip
    // ---------------------------------------------------------------

    /**
     * Kunci per code_full agar dua request bersamaan tidak mendapat nomor yang sama.
     */
    function lock_kode($code_full)
    {
        $row = $this->db->query('SELECT GET_LOCK(?, 10) AS ok', ['arsip_kode_'.$code_full])->row();
        return $row && (int) $row->ok === 1;
    }

    function unlock_kode($code_full)
    {
        $this->db->query('SELECT RELEASE_LOCK(?)', ['arsip_kode_'.$code_full]);
    }

    /**
     * Kode arsip berikutnya: YY/MM/code_full/N.
     * Prefix diakhiri '/' supaya kode kategori induk tidak tertukar dengan sub-kategorinya
     * (mis. PDK.001 vs PDK.001.002).
     */
    function next_kode_arsip($code_full)
    {
        $prefix = date('y').'/'.date('m').'/'.$code_full.'/';
        $row = $this->db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(kode_arsip, '/', -1) AS UNSIGNED)) AS n
             FROM document WHERE kode_arsip LIKE ? ESCAPE '!'",
            [$this->db->escape_like_str($prefix).'%']
        )->row();
        $next = ($row && $row->n !== NULL) ? ((int) $row->n + 1) : 1;
        return $prefix.$next;
    }

    // ---------------------------------------------------------------
    // Dokumen & file
    // ---------------------------------------------------------------

    function insert_document($data)
    {
        $this->db->insert('document', $data);
        return (int) $this->db->insert_id();
    }

    function update_document($id_doc, $data)
    {
        return $this->db->where('id_doc', $id_doc)->update('document', $data);
    }

    function insert_files($id_doc, $files)
    {
        foreach ($files as $f) {
            $this->db->insert('files', [
                'id_doc'   => $id_doc,
                'filename' => $f['filename'],
                'realname' => $f['realname'],
            ]);
        }
    }

    function delete_files($id_doc, $filenames)
    {
        if (empty($filenames)) return;
        $this->db->where('id_doc', $id_doc)->where_in('filename', $filenames)->delete('files');
    }

    function insert_log($id_doc, $idusers, $activity)
    {
        $this->db->insert('doc_log', [
            'id_doc'       => $id_doc,
            'idusers'      => (string) $idusers,
            'activity'     => $activity,
            'created_date' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Data dokumen aktif (status A) beserta nama kategori, departemen, tipe file.
     */
    function get_document($id_doc)
    {
        return $this->db->select($this->_doc_columns())
            ->from('document d')
            ->join('categories c', 'c.id_category = d.id_category', 'left')
            ->join('departement p', 'p.id_departement = d.departement_owner', 'left')
            ->join('tipefile t', 't.id_tipefile = d.tipefile', 'left')
            ->where('d.id_doc', $id_doc)
            ->where('d.status', 'A')
            ->get()->row_array();
    }

    /**
     * Daftar file dokumen dari tabel files. Dokumen lama yang belum punya baris di
     * tabel files memakai kolom document.filename (dipisah koma).
     */
    function get_files($id_doc)
    {
        $map = $this->get_files_bulk([$id_doc]);
        return isset($map[$id_doc]) ? $map[$id_doc] : [];
    }

    // Ambil file untuk banyak dokumen sekaligus: [id_doc => [[filename, realname], ...]]
    function get_files_bulk($id_docs)
    {
        $map = [];
        if (empty($id_docs)) return $map;

        $rows = $this->db->select('id_doc, filename, realname')
            ->where_in('id_doc', $id_docs)
            ->order_by('id', 'ASC')
            ->get('files')->result_array();
        foreach ($rows as $r) {
            $map[(int) $r['id_doc']][] = ['filename' => $r['filename'], 'realname' => (string) $r['realname']];
        }

        $missing = array_values(array_diff(array_map('intval', $id_docs), array_keys($map)));
        if (!empty($missing)) {
            $docs = $this->db->select('id_doc, filename')->where_in('id_doc', $missing)->get('document')->result_array();
            foreach ($docs as $d) {
                foreach (array_filter(array_map('trim', explode(',', (string) $d['filename']))) as $fn) {
                    $map[(int) $d['id_doc']][] = ['filename' => $fn, 'realname' => ''];
                }
            }
        }
        return $map;
    }

    /**
     * Pencarian dokumen aktif. $f berisi filter yang sudah divalidasi controller.
     * Return ['items' => [...], 'total' => n]
     */
    function search($f, $page, $limit, $sort, $order)
    {
        $this->db->from('document d')
            ->join('categories c', 'c.id_category = d.id_category', 'left')
            ->join('departement p', 'p.id_departement = d.departement_owner', 'left')
            ->join('tipefile t', 't.id_tipefile = d.tipefile', 'left')
            ->where('d.status', 'A');

        if (!empty($f['q'])) {
            $this->db->group_start()
                ->like('d.keyword', $f['q'])
                ->or_like('d.summary', $f['q'])
                ->or_like('d.kode_arsip', $f['q'])
                ->group_end();
        }
        if (!empty($f['category'])) {
            if (!empty($f['include_sub'])) {
                $this->db->group_start()
                    ->where('c.code_full', $f['category']->code_full)
                    ->or_like('c.code_full', $f['category']->code_full.'.', 'after')
                    ->group_end();
            } else {
                $this->db->where('d.id_category', (string) $f['category']->id_category);
            }
        }
        if (!empty($f['departement_owner'])) $this->db->where('d.departement_owner', $f['departement_owner']);
        if (!empty($f['tipefile']))          $this->db->where('d.tipefile', $f['tipefile']);
        if (!empty($f['year']))              $this->db->like('d.yearDate', $f['year'], 'after');
        if (!empty($f['doc_date']))          $this->db->where('d.yearDate', $f['doc_date']);
        if (!empty($f['created_from']))      $this->db->where('d.created_date >=', $f['created_from'].' 00:00:00');
        if (!empty($f['created_to']))        $this->db->where('d.created_date <=', $f['created_to'].' 23:59:59');
        if (isset($f['public']))             $this->db->where('d.public', $f['public']);

        // Hitung total tanpa mereset query builder, lalu ambil halaman yang diminta
        $total = $this->db->count_all_results('', FALSE);

        $items = $this->db->select($this->_doc_columns())
            ->order_by($sort, $order)
            ->order_by('d.id_doc', $order)
            ->limit($limit, ($page - 1) * $limit)
            ->get()->result_array();

        return ['items' => $items, 'total' => $total];
    }

    private function _doc_columns()
    {
        return 'd.id_doc, d.kode_arsip, d.yearDate AS doc_date, d.id_category, c.code_full, c.name_category, c.doc_status,
                d.departement_owner, p.nama_departement, d.tipefile, t.tipefile AS nama_tipefile,
                d.keyword, d.summary, d.filename, d.public, d.expired_public, d.active_until, d.link,
                d.created_by, d.created_date, d.edited_by, d.edited_date';
    }
}
