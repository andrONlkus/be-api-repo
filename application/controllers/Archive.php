<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Arsip_REST_Controller.php';
use Restserver\Libraries\REST_Controller;

/**
 * API arsip umum (lihat analisa/06-rancangan-api-arsip.md).
 * Route (config/routes.php):
 *   GET  /api/archive                          -> index_get   (pencarian)
 *   POST /api/archive                          -> index_post  (create)
 *   GET  /api/archive/{id_doc}                 -> item_get    (detail)
 *   POST /api/archive/{id_doc}                 -> item_post   (update)
 *   GET  /api/archive/{id_doc}/file/{filename} -> file_get    (download)
 */
class Archive extends Arsip_REST_Controller {

    const DEFAULT_USER  = 819;      // repository@prasetiyamulya.ac.id
    const DEFAULT_TYPES = 'pdf|jpg|jpeg|png|doc|docx|xls|xlsx|ppt|pptx';

    protected $query_key_methods = ['file'];

    function __construct()
    {
        parent::__construct();
        $this->load->model('m_archive');
    }

    // ---------------------------------------------------------------
    // GET /api/archive — pencarian
    // ---------------------------------------------------------------
    public function index_get()
    {
        $errors  = [];
        $filters = ['q' => trim((string) $this->get('q'))];

        $id_category = $this->get('id_category');
        if ($this->_filled($id_category)) {
            $id = $this->to_id($id_category);
            $filters['category'] = $id ? $this->m_archive->get_category($id) : NULL;
            if (empty($filters['category'])) $errors['id_category'] = 'Kategori tidak ditemukan atau tidak aktif';
            $filters['include_sub'] = $this->get('include_sub') === '1';
        }
        foreach (['departement_owner', 'tipefile'] as $field) {
            $value = $this->get($field);
            if ($this->_filled($value)) {
                $filters[$field] = $this->to_id($value);
                if ($filters[$field] === NULL) $errors[$field] = 'Harus berupa ID angka';
            }
        }

        $year = $this->get('year');
        if ($this->_filled($year)) {
            if (preg_match('/^\d{4}$/', $year)) $filters['year'] = $year;
            else $errors['year'] = 'Format tahun YYYY';
        }
        $doc_date = $this->get('doc_date');
        if ($this->_filled($doc_date)) {
            if ($this->valid_date($doc_date, 'Y-m')) $filters['doc_date'] = $doc_date;
            else $errors['doc_date'] = 'Format YYYY-MM';
        }
        foreach (['created_from', 'created_to'] as $field) {
            $value = $this->get($field);
            if ($this->_filled($value)) {
                if ($this->valid_date($value)) $filters[$field] = $value;
                else $errors[$field] = 'Format YYYY-MM-DD';
            }
        }
        $public = $this->get('public');
        if ($this->_filled($public)) {
            if (in_array($public, ['0', '1'], TRUE)) $filters['public'] = $public;
            else $errors['public'] = 'Nilai 0 atau 1';
        }

        $page  = $this->_filled($this->get('page')) ? $this->to_id($this->get('page')) : 1;
        $limit = $this->_filled($this->get('limit')) ? $this->to_id($this->get('limit')) : 20;
        if ($page === NULL) $errors['page'] = 'Harus angka >= 1';
        if ($limit === NULL || $limit > 100) $errors['limit'] = 'Harus angka 1-100';

        $sorts = ['created_date' => 'd.created_date', 'kode_arsip' => 'd.kode_arsip', 'doc_date' => 'd.yearDate', 'id_doc' => 'd.id_doc'];
        $sort  = $this->_filled($this->get('sort')) ? $this->get('sort') : 'created_date';
        $order = $this->_filled($this->get('order')) ? strtolower($this->get('order')) : 'desc';
        if (!isset($sorts[$sort])) $errors['sort'] = 'Pilihan: '.implode(', ', array_keys($sorts));
        if (!in_array($order, ['asc', 'desc'], TRUE)) $errors['order'] = 'Pilihan: asc, desc';

        if (!empty($errors)) {
            return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Validasi gagal', $errors);
        }

        $result = $this->m_archive->search($filters, $page, $limit, $sorts[$sort], $order);
        $files  = $this->m_archive->get_files_bulk(array_column($result['items'], 'id_doc'));

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = $this->_format_doc($row, isset($files[(int) $row['id_doc']]) ? $files[(int) $row['id_doc']] : []);
        }

        $this->success([
            'items'      => $items,
            'pagination' => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $limit),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // GET /api/archive/{id_doc} — detail
    // ---------------------------------------------------------------
    public function item_get($id_doc = NULL)
    {
        $id_doc = $this->to_id((string) $id_doc);
        $doc = $id_doc ? $this->m_archive->get_document($id_doc) : NULL;
        if (empty($doc)) {
            return $this->error(REST_Controller::HTTP_NOT_FOUND, 'Arsip tidak ditemukan');
        }
        $this->success($this->_format_doc($doc, $this->m_archive->get_files($id_doc)));
    }

    // ---------------------------------------------------------------
    // POST /api/archive — create
    // ---------------------------------------------------------------
    public function index_post()
    {
        $v = $this->_validate_fields(TRUE);
        $errors = $v['errors'];
        $files  = $this->_incoming_files();
        if (empty($files)) {
            $errors['userfile'] = 'Minimal 1 file wajib diupload (field userfile[])';
        }
        if (empty($errors) && ($e = $this->_check_files($files, $v['departement']->upload_limit)) !== NULL) {
            $errors['userfile'] = $e;
        }
        if (!empty($errors)) {
            return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Validasi gagal', $errors);
        }

        $d = $v['data'];
        if ($v['validate_access'] && !$this->m_archive->has_category_access($d['id_category'], $d['departement_owner'])) {
            return $this->error(REST_Controller::HTTP_FORBIDDEN, 'Departemen tidak memiliki akses upload ke kategori ini');
        }

        $uploaded = $this->_upload_files($files, $d['yearDate'], $v['departement']->upload_limit, $upload_error);
        if ($uploaded === FALSE) {
            return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Upload file gagal', ['userfile' => $upload_error]);
        }

        if (!isset($d['active_until'])) {
            $d['active_until'] = date('Y-m-d', strtotime('+'.(int) $v['category']->doc_life.' years'));
        }
        $code_full = $v['category']->code_full;
        if (!$this->m_archive->lock_kode($code_full)) {
            $this->_remove_physical(array_column($uploaded, 'filename'));
            return $this->error(REST_Controller::HTTP_SERVICE_UNAVAILABLE, 'Server sibuk, silakan coba lagi');
        }

        $this->db->trans_begin();
        $kode_arsip = $this->m_archive->next_kode_arsip($code_full);
        $id_doc = $this->m_archive->insert_document(array_merge([
            'public'         => '0',
            'expired_public' => NULL,
            'link'           => '',
        ], $d, [
            'kode_arsip'   => $kode_arsip,
            'filename'     => implode(',', array_column($uploaded, 'filename')),
            'status'       => 'A',
            'isABulk'      => 'N',
            'created_by'   => (string) $v['user'],
            'created_date' => date('Y-m-d H:i:s'),
        ]));
        $this->m_archive->insert_files($id_doc, $uploaded);
        $this->m_archive->insert_log($id_doc, $v['user'], 'Create via API');

        if ($this->db->trans_status() === FALSE || $id_doc < 1) {
            $this->db->trans_rollback();
            $this->m_archive->unlock_kode($code_full);
            $this->_remove_physical(array_column($uploaded, 'filename'));
            return $this->error(REST_Controller::HTTP_INTERNAL_SERVER_ERROR, 'Gagal menyimpan arsip ke database');
        }
        $this->db->trans_commit();
        $this->m_archive->unlock_kode($code_full);

        $this->success([
            'id_doc'     => $id_doc,
            'kode_arsip' => $kode_arsip,
            'files'      => $this->_format_files($id_doc, $uploaded),
        ], REST_Controller::HTTP_CREATED, 'Created');
    }

    // ---------------------------------------------------------------
    // POST /api/archive/{id_doc} — update
    // ---------------------------------------------------------------
    public function item_post($id_doc = NULL)
    {
        $id_doc = $this->to_id((string) $id_doc);
        $doc = $id_doc ? $this->m_archive->get_document($id_doc) : NULL;
        if (empty($doc)) {
            return $this->error(REST_Controller::HTTP_NOT_FOUND, 'Arsip tidak ditemukan');
        }

        $v = $this->_validate_fields(FALSE);
        $errors = $v['errors'];
        $d = $v['data'];

        $file_mode = $this->_filled($this->post('file_mode')) ? $this->post('file_mode') : 'append';
        if (!in_array($file_mode, ['append', 'replace'], TRUE)) {
            $errors['file_mode'] = 'Pilihan: append, replace';
        }

        $existing = array_column($this->m_archive->get_files($id_doc), 'filename');
        $remove = $this->post('delete_files');
        $remove = is_array($remove) ? $remove : ($this->_filled($remove) ? [$remove] : []);
        $remove = array_values(array_unique(array_filter(array_map('trim', $remove), 'strlen')));
        $unknown = array_diff($remove, $existing);
        if (!empty($unknown)) {
            $errors['delete_files'] = 'File bukan milik arsip ini: '.implode(', ', $unknown);
        }

        $files = $this->_incoming_files();
        if ($file_mode === 'replace' && empty($files)) {
            $errors['userfile'] = 'file_mode=replace membutuhkan minimal 1 file baru';
        }

        $id_dept = isset($d['departement_owner']) ? $d['departement_owner'] : (int) $doc['departement_owner'];
        $dept = isset($v['departement']) ? $v['departement'] : $this->m_archive->get_departement($id_dept);
        if (!empty($files) && empty($errors)) {
            $limit = $dept ? $dept->upload_limit : 20;
            if (($e = $this->_check_files($files, $limit)) !== NULL) $errors['userfile'] = $e;
        }

        if ($file_mode === 'replace') {
            $remove = $existing;
        }
        $remaining = array_values(array_diff($existing, $remove));
        if (empty($errors) && empty($files) && empty($remaining)) {
            $errors['delete_files'] = 'Arsip harus tetap memiliki minimal 1 file';
        }
        if (empty($errors) && empty($d) && empty($files) && empty($remove)) {
            $errors['_'] = 'Tidak ada data yang diubah';
        }
        if (!empty($errors)) {
            return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Validasi gagal', $errors);
        }

        if ($v['validate_access']) {
            $id_category = isset($d['id_category']) ? $d['id_category'] : $doc['id_category'];
            if (!$this->m_archive->has_category_access($id_category, $id_dept)) {
                return $this->error(REST_Controller::HTTP_FORBIDDEN, 'Departemen tidak memiliki akses edit ke kategori ini');
            }
        }

        $uploaded = [];
        if (!empty($files)) {
            $doc_date = isset($d['yearDate']) ? $d['yearDate'] : $doc['doc_date'];
            $uploaded = $this->_upload_files($files, $doc_date, $dept ? $dept->upload_limit : 20, $upload_error);
            if ($uploaded === FALSE) {
                return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Upload file gagal', ['userfile' => $upload_error]);
            }
        }

        $this->db->trans_begin();
        $this->m_archive->update_document($id_doc, array_merge($d, [
            'filename'    => implode(',', array_merge($remaining, array_column($uploaded, 'filename'))),
            'edited_by'   => $v['user'],
            'edited_date' => date('Y-m-d H:i:s'),
        ]));
        $this->m_archive->delete_files($id_doc, $remove);
        $this->m_archive->insert_files($id_doc, $uploaded);
        $this->m_archive->insert_log($id_doc, $v['user'], 'Update via API');

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            $this->_remove_physical(array_column($uploaded, 'filename'));
            return $this->error(REST_Controller::HTTP_INTERNAL_SERVER_ERROR, 'Gagal menyimpan perubahan ke database');
        }
        $this->db->trans_commit();

        // File fisik lama baru dihapus setelah database berhasil diperbarui
        $this->_remove_physical($remove);

        $doc = $this->m_archive->get_document($id_doc);
        $this->success($this->_format_doc($doc, $this->m_archive->get_files($id_doc)), REST_Controller::HTTP_OK, 'Updated');
    }

    // ---------------------------------------------------------------
    // GET /api/archive/{id_doc}/file/{filename} — download
    // ---------------------------------------------------------------
    public function file_get($id_doc = NULL, $filename = NULL)
    {
        $id_doc   = $this->to_id((string) $id_doc);
        $filename = rawurldecode((string) $filename);

        $doc = $id_doc ? $this->m_archive->get_document($id_doc) : NULL;
        if (empty($doc)) {
            return $this->error(REST_Controller::HTTP_NOT_FOUND, 'Arsip tidak ditemukan');
        }
        $file = NULL;
        foreach ($this->m_archive->get_files($id_doc) as $f) {
            if ($f['filename'] === $filename) $file = $f;
        }
        $path = $this->arsip_path().basename($filename);
        if ($file === NULL || !is_file($path)) {
            return $this->error(REST_Controller::HTTP_NOT_FOUND, 'File tidak ditemukan');
        }

        $this->m_archive->insert_log($id_doc, self::DEFAULT_USER, 'Download via API');

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $download_name = ($file['realname'] !== '' ? $file['realname'] : pathinfo($filename, PATHINFO_FILENAME)).($ext !== '' ? '.'.$ext : '');
        $ascii_name = str_replace(['"', '\\'], '', preg_replace('/[^\x20-\x7E]/', '_', $download_name));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$ascii_name.'"; filename*=UTF-8\'\''.rawurlencode($download_name));
        header('Content-Length: '.filesize($path));
        header('Cache-Control: private, no-cache');
        readfile($path);
        exit;
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    /**
     * Validasi field metadata. $create = TRUE berarti field wajib harus ada;
     * untuk update hanya field yang dikirim yang divalidasi & diubah.
     * Return: data (kolom document), errors, category, departement, user, validate_access
     */
    private function _validate_fields($create)
    {
        $errors = [];
        $data = [];
        $out = ['category' => NULL];

        $id = $this->post('id_category');
        if ($this->_filled($id)) {
            $cat = $this->to_id($id) ? $this->m_archive->get_category($this->to_id($id)) : NULL;
            if (empty($cat)) {
                $errors['id_category'] = 'Kategori tidak ditemukan atau tidak aktif';
            } elseif ((int) $cat->level < 2) {
                $errors['id_category'] = 'Kategori level 1 tidak dapat dipakai, pilih kategori level 2-4';
            } else {
                $data['id_category'] = (string) $cat->id_category;
                $out['category'] = $cat;
            }
        } elseif ($create) {
            $errors['id_category'] = 'Wajib diisi';
        }

        $id = $this->post('departement_owner');
        if ($this->_filled($id)) {
            $dept = $this->to_id($id) ? $this->m_archive->get_departement($this->to_id($id)) : NULL;
            if (empty($dept)) {
                $errors['departement_owner'] = 'Departemen tidak ditemukan atau tidak aktif';
            } else {
                $data['departement_owner'] = (int) $dept->id_departement;
                $out['departement'] = $dept;
            }
        } elseif ($create) {
            $errors['departement_owner'] = 'Wajib diisi';
        }

        $id = $this->post('tipefile');
        if ($this->_filled($id)) {
            $tipe = $this->to_id($id) ? $this->m_archive->get_tipefile($this->to_id($id)) : NULL;
            if (empty($tipe)) $errors['tipefile'] = 'Tipe file tidak ditemukan atau tidak aktif';
            else $data['tipefile'] = (int) $tipe->id_tipefile;
        } elseif ($create) {
            $errors['tipefile'] = 'Wajib diisi';
        }

        $value = $this->post('doc_date');
        if ($this->_filled($value)) {
            if ($this->valid_date($value, 'Y-m')) $data['yearDate'] = $value;
            else $errors['doc_date'] = 'Format YYYY-MM';
        } elseif ($create) {
            $errors['doc_date'] = 'Wajib diisi';
        }

        foreach (['keyword', 'summary'] as $field) {
            $value = $this->post($field);
            if ($this->_filled($value)) $data[$field] = trim($value);
            elseif ($create) $errors[$field] = 'Wajib diisi';
        }

        foreach (['active_until', 'expired_public'] as $field) {
            $value = $this->post($field);
            if ($this->_filled($value)) {
                if ($this->valid_date($value)) $data[$field] = $value;
                else $errors[$field] = 'Format YYYY-MM-DD';
            }
        }

        $value = $this->post('public');
        if ($this->_filled($value)) {
            if (in_array($value, ['0', '1'], TRUE)) $data['public'] = $value;
            else $errors['public'] = 'Nilai 0 atau 1';
        }

        $value = $this->post('link');
        if ($value !== NULL && !is_array($value)) {
            if (strlen($value) > 155) $errors['link'] = 'Maksimal 155 karakter';
            else $data['link'] = trim($value);
        }

        $user_field = $create ? 'created_by' : 'edited_by';
        $out['user'] = self::DEFAULT_USER;
        $value = $this->post($user_field);
        if ($this->_filled($value)) {
            $uid = $this->to_id($value);
            if ($uid === NULL || !$this->m_archive->user_exists($uid)) $errors[$user_field] = 'User tidak ditemukan atau tidak aktif';
            else $out['user'] = $uid;
        }

        $value = $this->post('validate_access');
        $out['validate_access'] = FALSE;
        if ($this->_filled($value)) {
            if (in_array($value, ['0', '1'], TRUE)) $out['validate_access'] = $value === '1';
            else $errors['validate_access'] = 'Nilai 0 atau 1';
        }

        $out['data'] = $data;
        $out['errors'] = $errors;
        return $out;
    }

    // File dari field userfile[] (atau userfile tunggal), dinormalisasi ke daftar
    private function _incoming_files()
    {
        if (empty($_FILES['userfile'])) return [];
        $f = $_FILES['userfile'];
        $list = [];
        $count = is_array($f['name']) ? count($f['name']) : 1;
        for ($i = 0; $i < $count; $i++) {
            $item = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
                $item[$k] = is_array($f[$k]) ? $f[$k][$i] : $f[$k];
            }
            if ($item['error'] === UPLOAD_ERR_NO_FILE || $item['name'] === '') continue;
            $list[] = $item;
        }
        return $list;
    }

    // Cek ekstensi, error PHP & ukuran sebelum ada file yang disimpan. Return pesan error atau NULL.
    private function _check_files($files, $upload_limit_mb)
    {
        $allowed = explode('|', strtolower($this->_allowed_types()));
        $max_bytes = (int) $upload_limit_mb * 1000 * 1024;
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, TRUE)) {
                return $f['name'].': ekstensi .'.$ext.' tidak diizinkan (boleh: '.implode(', ', $allowed).')';
            }
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                return $f['name'].': ukuran melebihi batas server (upload_max_filesize)';
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                return $f['name'].': upload gagal (kode error '.$f['error'].')';
            }
            if ($max_bytes > 0 && $f['size'] > $max_bytes) {
                return $f['name'].': ukuran melebihi batas departemen ('.(int) $upload_limit_mb.' MB)';
            }
        }
        return NULL;
    }

    /**
     * Simpan file ke arsip_path dengan nama DOC_{doc_date}{timestamp}{index}.{ext}.
     * Jika satu file gagal, file yang sudah tersimpan dihapus kembali dan return FALSE.
     */
    private function _upload_files($files, $doc_date, $upload_limit_mb, &$error)
    {
        $this->load->library('upload');
        $stamp = round(microtime(TRUE));
        $saved = [];
        foreach ($files as $i => $f) {
            $_FILES['arsip_file'] = $f;
            $_FILES['arsip_file']['name'] = 'DOC_'.$doc_date.$stamp.$i.'.'.pathinfo($f['name'], PATHINFO_EXTENSION);
            $this->upload->initialize([
                'upload_path'   => $this->arsip_path(),
                'allowed_types' => $this->_allowed_types(),
                'max_size'      => (int) $upload_limit_mb * 1000,
                'overwrite'     => FALSE,
            ]);
            if (!$this->upload->do_upload('arsip_file')) {
                $error = $f['name'].': '.trim(strip_tags($this->upload->display_errors()));
                $this->_remove_physical(array_column($saved, 'filename'));
                return FALSE;
            }
            $up = $this->upload->data();
            $saved[] = ['filename' => $up['file_name'], 'realname' => pathinfo($f['name'], PATHINFO_FILENAME)];
        }
        unset($_FILES['arsip_file']);
        return $saved;
    }

    private function _remove_physical($filenames)
    {
        foreach ($filenames as $fn) {
            $path = $this->arsip_path().basename($fn);
            if (is_file($path) && !@unlink($path)) {
                log_message('error', 'Arsip API: gagal menghapus file '.$path);
            }
        }
    }

    private function _allowed_types()
    {
        $types = $this->config->item('arsip_allowed_types');
        return empty($types) ? self::DEFAULT_TYPES : $types;
    }

    private function _format_files($id_doc, $files)
    {
        $out = [];
        foreach ($files as $f) {
            $out[] = [
                'filename' => $f['filename'],
                'realname' => $f['realname'],
                'url'      => $this->file_url($id_doc, $f['filename']),
            ];
        }
        return $out;
    }

    private function _format_doc($row, $files)
    {
        return [
            'id_doc'         => (int) $row['id_doc'],
            'kode_arsip'     => $row['kode_arsip'],
            'doc_date'       => $row['doc_date'],
            'category'       => [
                'id_category'   => (int) $row['id_category'],
                'code_full'     => $row['code_full'],
                'name_category' => $row['name_category'],
                'doc_status'    => $row['doc_status'],
            ],
            'departement'    => ['id_departement' => (int) $row['departement_owner'], 'nama_departement' => $row['nama_departement']],
            'tipefile'       => ['id_tipefile' => (int) $row['tipefile'], 'tipefile' => $row['nama_tipefile']],
            'keyword'        => $row['keyword'],
            'summary'        => $row['summary'],
            'public'         => $row['public'],
            'expired_public' => $row['expired_public'],
            'active_until'   => $row['active_until'],
            'link'           => $row['link'],
            'created_by'     => $row['created_by'] !== NULL ? (int) $row['created_by'] : NULL,
            'created_date'   => $row['created_date'],
            'edited_by'      => $row['edited_by'] !== NULL ? (int) $row['edited_by'] : NULL,
            'edited_date'    => $row['edited_date'],
            'files'          => $this->_format_files((int) $row['id_doc'], $files),
        ];
    }

    private function _filled($value)
    {
        return is_string($value) && trim($value) !== '';
    }
}
