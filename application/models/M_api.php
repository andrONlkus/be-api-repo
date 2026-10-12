<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');
class M_api extends CI_Model {
    // function __construct()
    // {
    //     parent::__construct();
    //     $this->load->database('default');
    // }
    
    function add_doc($images = false, $filedata = false){
        $school = $this->post('school');
        if($school == 'SBE'){
            $id_category = '1075';
        }elseif($school == 'STEM'){
            $id_category = '1076';
        }        
        $get_codefull = $this->get_codefull($id_category);
        $public =  '1';
  
        if($public == NULL){
            $public = '0';
            $public_code = null;
        }else{
            $public_code = $this->unique_id();
        }
        $year = date('y');
        $month= date('m');
        
        $query = $this->db->query("SELECT kode_arsip AS MAX FROM document WHERE kode_arsip LIKE ? ORDER BY id_doc DESC LIMIT 1 ", $year.'/'.$month.'/'.$get_codefull->code_full.'%');
        $row = $query->row();
  
        // $nomor_ujung
        $nomorakhir = @$row->MAX; // 21/03/HGL.HKM.001.001/xxx
        if($nomorakhir!= null){  
            $last_kode = explode('/', $nomorakhir);
    
            $nomorakhir = $last_kode[3];
            $nomor_baru = $nomorakhir + 1;
            $kd_arsip = $last_kode[0].'/'.$last_kode[1].'/'.$last_kode[2].'/'.$nomor_baru;
        }else{ 
            $new_kode_arsip = $year.'/'.$month.'/'.$get_codefull->code_full.'/1';
            $kd_arsip = $new_kode_arsip;
        }
        if($images != ''){
            $images = implode(',', $images);
            $doc      = $images;
        }else{
            $doc = '';
        }
                
        $data = [
            'yearDate'          => $this->post('doc_date'),
            'kode_arsip'        => $kd_arsip,
            'id_category'       => $id_category,
            'keyword'           => 'Ijazah,Transkrip,'.$this->post('nim'),
            'summary'           => 'Ijazah dan Transkrip - '.$this->post('nim'),
            'filename'          => $doc,
            'status'            => 'A',
            'isABulk'           => 'N',
            'created_by'        => '819',         // repository@pmbs.ac.id 
            'created_date'      => date("y-m-d H:i:s"),
            'active_until'      => date('Y-m-d H:i:s', strtotime($this->post('expired_date'))),
            'departement_owner' => '12',          // Admin Univ 
            'tipefile'          => '8',           // Arsip Adm Mahasiswa 
            'public'            => $public,
            'expired_public'    => @$this->post('exp_public'),
            'link'              => '',            // $this->post('link'),
            // 'public_code'       => $public_code
        ];
        
        $this->db->insert('document', $data);
        $insert_id = $this->db->insert_id();
        //utk filename
        $jumlahfile = count($filedata);
        if($filedata > 0){
            for ($i=0; $i < $jumlahfile; $i++) { 
                $arrFiles = array('id_doc'  => $insert_id ,
                                'filename'=> $filedata[$i]['filename'],
                                'realname'=> $filedata[$i]['realname'],
                                );
                $this->db->insert('files', $arrFiles);
            }
        }
        
        $datalog = array(
            'id_doc'         => $insert_id ,
            'idusers'        => '819',
            // 'username'       => 'repository@pmbs.ac.id',
            'activity'       => 'Create via API',
            'created_date'   => date("Y-m-d H:i:s")
            );
        $this->db->insert('doc_log', $datalog);
  
        $affected['result'] = $this->db->affected_rows();
        $affected['kd_arsip'] = $kd_arsip;
        $affected['department'] = $this->session->userdata('departement');
        return $affected;
  
    }

    function get_codefull($id_category = null){
        $query = $this->db->query("SELECT code_full FROM categories WHERE id_category = '$id_category'");
        if($query->num_rows() > 0){
            return $query->row();
        }else{
            return false;
        }
    }

    function unique_id($l = 8) {
        return substr(md5(uniqid(mt_rand(), true)), 0, $l);
    }

    function getMaxNumber($month, $year, $code_full){
        $query = $this->db->query("SELECT kode_arsip AS MAX FROM document WHERE kode_arsip LIKE ? ORDER BY id_doc DESC LIMIT 1 ", $year.'/'.$month.'/'.$code_full.'%');
        $row = $query->row();

        return $row;
    }
    
    function insert_docs($data){
        $this->db->insert('document', $data);
        $insert_id = $this->db->insert_id();
        return $insert_id;
    }

    function update_docs($id_doc, $data){
        $this->db->where('id_doc', $id_doc);
        $affected = $this->db->update('document', $data);
        return $affected;
    }

    function insert_files($data){
        $this->db->insert('files', $data);
        $insert_id = $this->db->insert_id();
        return $insert_id;
    }

    function getDocumentByKeyword($keyword){
        $result = $this->db->query("SELECT * FROM document WHERE status='A' AND (keyword = 'Ijazah,Transkrip,$keyword' OR summary = 'Ijazah dan Transkrip - $keyword') ORDER BY created_date DESC LIMIT 1");
        // $this->db->select('*');
        // $this->db->from('document');
        // $this->db->where('status', 'A');
        // $this->db->like('keyword', $keyword);
        // $this->db->or_like('summary', $keyword);
        // $this->db->order_by('created_date', 'DESC');
        // $this->db->limit(1);
        $data = $result->row_array();
        return  $data;        
    }

    function get_files($id_doc){
        $this->db->select('id_doc,kode_arsip,filename');
        $this->db->where('id_doc', $id_doc);
        $query = $this->db->get('document');
        return $query->result();
    }

    function get_data_files($id_doc){
        $this->db->select('*');
        $this->db->where('id_doc', $id_doc);
        $query = $this->db->get('files');
        return $query->result();
    }

    function delete_file($id_file){
        $this->db->where('id', $id_file);
        $affected = $this->db->delete('files');
        return $affected;
    }

    function getFiles($id_doc){
        $this->db->select('filename, realname');
        $this->db->from('files');
        $this->db->where('id_doc', $id_doc);
        $res = $this->db->get()->result();
        //var_dump($res);
        if(!empty($res)){
            $i=0;
            foreach ($res as $key => $value) {
                $files[$i]['id_doc'] = $id_doc;
                $files[$i]['filename'] = $value->filename;
                $files[$i]['realname'] = $value->realname;
                $i++;
            }

        }else{
            $res = $this->get_files($id_doc);
            $filename = explode(',', $res[0]->filename);
            $i=0;
            foreach ($filename as $key => $value) {
                $files[$i]['id_doc'] = $id_doc;
                $files[$i]['filename'] = $value;
                $files[$i]['realname'] = "";
                $i++;
            }
        }
        return  $files;
    }

    function get_data_doc($id_doc){
        $this->db->select('a.id_doc as id_doc, a.kode_arsip, a.yearDate, b.name_category as category, a.keyword as keyword, a.summary as summary, a.filename as pdf_file, a.active_until, a.tipefile, a.public, b.doc_status, a.published, a.expired_public,a.created_by, a.link, c.nama_departement, d.username as email_hod, e.alasan_request as alasan');
        $this->db->from('document a');
        $this->db->join('categories b','a.id_category = b.id_category');
        $this->db->join('departement c','a.departement_owner = c.id_departement');
        $this->db->join('users d','c.hod = d.idusers');
        $this->db->join('delete_request e','a.id_doc = e.id_doc' ,'left');
        //$this->db->where('a.status', 'A');
        $this->db->where('a.id_doc', $id_doc);
        $result = $this->db->get()->result();
        for ($i=0; $i < count($result) ; $i++) {
          $file_list = explode(',', $result[$i]->pdf_file);
  
          if(is_array($file_list) && !empty($file_list[0])){
            foreach ($file_list as $value) {
              $files[] = $this->getFilesv2($value);
            }
            $result[$i]->files = $files;
          }//filename
          // $hasil_query[$i]['filename'] = $files;
          // $files = $this->getFiles($result[$i]->id_doc);
          // if(!empty($files)){
            
          //} 
        }
        return $result;
    }

    function getFilesv2($filename){
        $this->db->select('filename, realname');
        $this->db->from('files');
        $this->db->where('filename', $filename);
        $res = $this->db->get()->row_array();
        if(!empty($res)){
            $files['filename'] = $res['filename'];
            $files['realname'] = $res['realname'];
        }else{
            $files['filename'] = $filename;
            $files['realname'] = '';
        }
        //var_dump($res);
        // $this->db->get();
        // var_dump($this->db->last_query());exit;
        return  $files;
    }

    function getRealname($filename){
        $this->db->select('realname');
        $this->db->from('files');
        $this->db->where('filename', $filename);
        $data = $this->db->get()->row();
        return  $data;
    }

    function get_upload_limit(){
        // $departement_id = $this->session->userdata('id_departement');
        $this->db->select('upload_limit');
        $this->db->from('departement');
        $this->db->where('id_departement', '12');
        $res = $this->db->get();
        $res = $res->row();
        $upload_limit = $res->upload_limit * 1000;
        return $upload_limit;
    }

    function insert_log($id_doc){
        $datalog = array(
            'id_doc'         => $id_doc ,
            'idusers'        => '819',
            // 'username'       => 'repository@pmbs.ac.id',
            'activity'       => 'Create via API',
            'created_date'   => date("Y-m-d H:i:s")
            );
        $this->db->insert('doc_log', $datalog);
        $affected = $this->db->affected_rows();
        return $affected;
    }

    function insert_log_update($id_doc){
        $datalog = array(
            'id_doc'         => $id_doc ,
            'idusers'        => '819',
            // 'username'       => 'repository@pmbs.ac.id',
            'activity'       => 'Update via API',
            'created_date'   => date("Y-m-d H:i:s")
            );
        $this->db->insert('doc_log', $datalog);
        $affected = $this->db->affected_rows();
        return $affected;
    }

    function download_log($id_doc){
        $datalog = array(
            'id_doc'         => $id_doc ,
            'idusers'        => '819',
            // 'username'       => 'repository@pmbs.ac.id',
            'activity'       => 'Download via API',
            'created_date'   => date("Y-m-d H:i:s")
            );
        $this->db->insert('doc_log', $datalog);
        $affected = $this->db->affected_rows();
        return $affected;
    }
}
?>