<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require APPPATH . '/libraries/REST_Controller.php';
use Restserver\Libraries\REST_Controller;

require_once ('assets/aliyun-oss-php-sdk-master/autoload.php');
use OSS\OssClient;

class Api extends REST_Controller {

    private $env;  

    function __construct()
    {
        parent::__construct();
        $this->env = 'dev'; // 'dev' | 'prd'
        // $this->load->model('M_api');

        // $this->load->library('cors');
        // $this->cors->handle();
    }

    // Folder penyimpanan arsip. Diatur lewat $config['arsip_path'] di config.php,
    // default ke mount OSS production bila tidak diisi. Path relatif dihitung dari FCPATH.
    private function _arsip_path(){
        $path = $this->config->item('arsip_path');
        if(empty($path)){
            $path = '/mnt/ossfs/arsip/';
        }elseif($path[0] !== '/'){
            $path = FCPATH.$path;
        }
        return rtrim($path, '/').'/';
    }

    public function index_get(){
        $result = array(
            'status'    => 'S',
            'code'      => '200',
            'message'   => 'Test API Success!'
        );

        $this->response($result, REST_Controller::HTTP_OK);
    }

    // Get document from repository
    public function document_get(){
        $nim = $this->get('nim');
        // print_r($nim);
        // exit();
        if($nim !== ''){
            $getDoc = $this->m_api->getDocumentByKeyword($nim);
            // print_r($getDoc);
            // exit();
            if(!empty($getDoc)){                
                $id_doc = $getDoc['id_doc'];
                
                $getDetail = $this->m_api->get_data_doc($id_doc); 
                $getFile = $this->m_api->getFiles($id_doc);

                $files = '';
                if(!empty($getFile)){
                    foreach($getFile as $key => $value){
                        $files .= base_url() . "index.php/api/download_file/" . $value['filename'] . "/" . $value['id_doc'] . "|".$value['realname'].";"; 
                    }
                }

                $data = array(
                    'keyword'   => $getDetail[0]->keyword,
                    'summary'   => $getDetail[0]->summary,
                    'ListOfFiles' => $files
                );

                $result = array(
                    'status'    => 'S',
                    'code'      => '200',
                    'Message'   => 'Success',
                    'data'      => $data
                );

                $this->response($result, REST_Controller::HTTP_OK);
            }else{
                $result = array(
                    'status'    => 'E',
                    'code'      => '400',
                    'Message'   => 'Record is empty',
                    'data'      => ''
                );

                $this->response($result, REST_Controller::HTTP_NOT_FOUND);
            }
        }else{
            $result = array(
                'status'    => 'E',
                'code'      => '400',
                'Message'   => 'NIM is empty'
            );

            $this->response($result, REST_Controller::HTTP_NOT_FOUND);
        }       

    }

    public function download_file_get($filename  = false, $id_doc = false){
        $this->load->helper('download');
        //Load codeigniter FTP class
        $this->load->library('ftp');
        $this->m_api->download_log($id_doc);

        //get real filename from db
        //production
        $files = $this->_arsip_path().$filename;
        //lokal
        // $files = FCPATH.'assets/arsip/'.$filename;
        // if($this->env == 'dev'){   
        //     //FTP Configuration
        //     $ftp_config['hostname'] = '147.139.172.134';    // 147.139.172.134 https://ws-dev.prasetiyamulya.ac.id
        //     $ftp_config['username'] = 'devapi';
        //     $ftp_config['password'] = '@Develapi2023!';
        //     $ftp_config['debug']    = TRUE;
        //     $ftp_config['port']     = 21;

        //     //Connect to the remote server
        //     $this->ftp->connect($ftp_config);
            
        //     //File upload path of remote server
        //     $source = '/var/www/webservice/public_html/repository/assets/arsip/'.$fileName;
        //     $destination = FCPATH.'assets/arsip/'.$filename;
            
        //     //Upload file to the remote server
        //     $res = $this->ftp->download($source, ".".$destination);
        //     if($res){
        //         $files = FCPATH.'assets/arsip/'.$filename;
        //     }else{
        //         $files = FCPATH.'assets/arsip/'.$filename;
        //     }
            
        //     //Close FTP connection
        //     $this->ftp->close();
        // }
        
        $realname = $this->m_api->getRealname($filename);

        if(!empty($realname)){
            $ext = pathinfo($files, PATHINFO_EXTENSION);
            $file_name = $realname->realname.".".$ext;
        }else{
            $file_name = basename($files);
        }        
        // print_r("> ".$filename."|"); 
        // print_r("> ".$files."|"); 
        // print_r("> ".$realname."|");
        // exit;
        if (file_exists($files)) {
    		header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename='.basename($file_name));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . filesize($files));
            ob_end_clean();
            readfile($files);
            // force_download($file_name, file_get_contents($files));
            exit;
    	}else{
            $result = array(
                'status'    => 'E',
                'code'      => '400',
                'Message'   => 'file not found, please contact administrator.'
            );

            $this->response($result, REST_Controller::HTTP_NOT_FOUND);
    		// echo 'file not found, please contact administrator.';
    		// echo '<br/>';
    		// echo '<a href="javascript:history.back(1);">Back</a>';
    	}
    }

    // Upload file to repository
    public function document_post(){
        //Load codeigniter FTP class
        $this->load->library('ftp');

        // Check if the file was ever upload/saved
        $ck_file = $this->m_api->getDocumentByKeyword($this->post('nim'));
        if(empty($ck_file)){
            // Upload file
            $jumlah = count($_FILES['userfile']['name']);

            if($_FILES['userfile']['name'][0] != ""){
                $upload_limit = $this->m_api->get_upload_limit();
                $config = array();
                // $config['upload_path'] = './assets/arsip/';         // Local
                $config['upload_path'] = $this->_arsip_path();       // config arsip_path (default /mnt/ossfs/arsip/)
                $config['allowed_types'] = '*';
                // $config['max_size']      = '20000';
                $config['max_size']      = $upload_limit;
                $config['overwrite']     = FALSE;
                $this->load->library('upload');
                $images = [];
                $filedata = [];
                $files = $_FILES;
                //FTP Configuration
                // $ftp_config['hostname'] = '147.139.172.134';    //147.139.172.134   https://ws-dev.prasetiyamulya.ac.id
                // $ftp_config['username'] = 'devapi';
                // $ftp_config['password'] = '@Develapi2023!';
                // $ftp_config['debug']    = TRUE;
                // $ftp_config['port']     = 21;

                // //Connect to the remote server
                // $conn = $this->ftp->connect($ftp_config);
                for($i=0; $i<$jumlah; $i++)
                {  
                    //echo $files['userfile']['name'][$i]; 
                    $namaA  = 'DOC_';
                    $namaB  = $this->post("doc_date");
                    $namaC  = round(microtime(true));   
                    $file_ext = pathinfo($files["userfile"]["name"][$i], PATHINFO_EXTENSION);
                    $_FILES['userfile']['name']= $namaA.$namaB.$namaC.$i.'.'.$file_ext;
                    $_FILES['userfile']['type']= $files['userfile']['type'][$i];
                    $_FILES['userfile']['tmp_name']= $files['userfile']['tmp_name'][$i];
                    $_FILES['userfile']['error']= $files['userfile']['error'][$i];
                    $_FILES['userfile']['size']= $files['userfile']['size'][$i];    

                    $this->upload->initialize($config);
                    if (!$this->upload->do_upload())
                    {
                        $error = array('error' => $this->upload->display_errors());
                        $result = "wrong_format";
                        echo json_encode($result);
                        exit;
                    } else {
                        $uploadData = $this->upload->data();  
                        $images[] = $uploadData['file_name'];
                        $filedata[] = array('filename' => $uploadData['file_name'] ,
                                            'realname' => pathinfo($files["userfile"]["name"][$i], PATHINFO_FILENAME) 
                                        );

                        // Upload to ftp
                        // var_dump($conn);
                        // if($this->env == 'dev'){                            
                        //     if($conn){
                        //         $fileName = $uploadData['file_name'];

                        //         //File path at local server
                        //         $source = FCPATH.'assets/arsip/'.$fileName;
                                
                        //         //File upload path of remote server
                        //         // $destination = '/var/www/webservice/public_html/repository/assets/arsip/'.$fileName;
                        //         $destination = $fileName;
                                
                        //         var_dump($this->ftp->list_files());
                        //         exit();

                        //         //Upload file to the remote server
                        //         $this->ftp->upload($source, $destination, 'auto', 0775);
                                
                        //         // if ($this->ftp->error_msg()) {
                        //         //     echo 'FTP Error: ' . $this->ftp->error_msg();
                        //         // } else {
                        //         //     echo 'File uploaded successfully!';
                        //         // }

                        //         //Close FTP connection
                        //         $this->ftp->close();
                                
                        //         //Delete file from local server
                        //         // @unlink($source);
                        //     }
                        // }
                    }
                }
            }else{
                $images = '';
                $filedata = [];
            }
            // Insert Data
            $school = $this->post('school');
            if($school == 'SBE'){
                $id_category = '1075';
            }elseif($school == 'STEM'){
                $id_category = '1076';
            }        
            $get_codefull = $this->m_api->get_codefull($id_category);
            $public =  '1';
    
            if($public == NULL){
                $public = '0';
                $public_code = null;
            }else{
                $public_code = $this->m_api->unique_id();
            }
            $year = date('y');
            $month= date('m');

            $getMaxNumber = $this->m_api->getMaxNumber($month, $year, $get_codefull->code_full);
            // $nomor_ujung
            $nomorakhir = @$getMaxNumber->MAX; // 21/03/HGL.HKM.001.001/xxx
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

            $data_insert = array(
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
            );
            $insert_id = $this->m_api->insert_docs($data_insert);

            //utk filename
            $jumlahfile = count($filedata);
            if($filedata > 0){
                for ($i=0; $i < $jumlahfile; $i++) { 
                    $arrFiles = array('id_doc'  => $insert_id ,
                                    'filename'=> $filedata[$i]['filename'],
                                    'realname'=> $filedata[$i]['realname'],
                                    );
                    $this->m_api->insert_files($arrFiles);
                }
            }

            // $result = $this->m_api->add_doc($images, $filedata);
            if ($insert_id > 0) {            
                $this->m_api->insert_log($insert_id);

                $data = array(
                    'NIM'       => $this->post('nim'),
                    'id_doc'    => $insert_id,
                    'KodeArsip' => $kd_arsip
                );

                $result = array(
                    'status'    => 'S',
                    'code'      => '200',
                    'data'      => $data
                );

                $this->response($result, REST_Controller::HTTP_OK);
            }else{
                $result = array(
                    'status'    => 'E',
                    'code'      => '400',
                    'Message'   => 'Upload documents/files are failed.'
                );

                $this->response($result, REST_Controller::HTTP_BAD_REQUEST);
            }
            // echo json_encode($result['result']);
        }else{
            $id_doc = $ck_file['id_doc'];
            $getFiles = $this->m_api->get_data_files($ck_file['id_doc']);
            
            if(!empty($getFiles)){
                foreach($getFiles as $key => $value){
                    // $source = FCPATH.'assets/arsip/'.$value->filename;   // Local
                    $source = $this->_arsip_path().$value->filename;         // config arsip_path
                    $del_file = unlink($source);
                    if($del_file){
                        $this->m_api->delete_file($value->id);
                    }
                    // if($this->env == 'dev'){
                    //     $dest = $value->filename;
                    //     $this->ftp->delete_files($dest);
                    // }
                }                
            }

            // Upload file
            $jumlah = count($_FILES['userfile']['name']);

            if($_FILES['userfile']['name'][0] != ""){
                $upload_limit = $this->m_api->get_upload_limit();
                $config = array();
                // $config['upload_path'] = './assets/arsip/';         // Local
                $config['upload_path'] = $this->_arsip_path();       // config arsip_path (default /mnt/ossfs/arsip/)
                $config['allowed_types'] = '*';
                // $config['max_size']      = '20000';
                $config['max_size']      = $upload_limit;
                $config['overwrite']     = FALSE;
                $this->load->library('upload');
                $images = [];
                $filedata = [];
                $files = $_FILES;
                for($i=0; $i<$jumlah; $i++)
                {  
                    //echo $files['userfile']['name'][$i]; 
                    $namaA  = 'DOC_';
                    $namaB  = $this->post("doc_date");
                    $namaC  = round(microtime(true));   
                    $file_ext = pathinfo($files["userfile"]["name"][$i], PATHINFO_EXTENSION);
                    $_FILES['userfile']['name']= $namaA.$namaB.$namaC.$i.'.'.$file_ext;
                    $_FILES['userfile']['type']= $files['userfile']['type'][$i];
                    $_FILES['userfile']['tmp_name']= $files['userfile']['tmp_name'][$i];
                    $_FILES['userfile']['error']= $files['userfile']['error'][$i];
                    $_FILES['userfile']['size']= $files['userfile']['size'][$i];    

                    $this->upload->initialize($config);
                    if (!$this->upload->do_upload())
                    {
                        $error = array('error' => $this->upload->display_errors());
                        $result = "wrong_format";
                        echo json_encode($result);
                        exit;
                    } else {
                        $uploadData = $this->upload->data();  
                        $images[] = $uploadData['file_name'];
                        $filedata[] = array('filename' => $uploadData['file_name'] ,
                                            'realname' => pathinfo($files["userfile"]["name"][$i], PATHINFO_FILENAME) 
                                        );

                        // Upload to ftp
                        // if($this->env == 'dev'){
                        //     $fileName = $uploadData['file_name'];

                        //     //FTP Configuration
                        //     $ftp_config['hostname'] = '147.139.172.134';    //147.139.172.134   https://ws-dev.prasetiyamulya.ac.id
                        //     $ftp_config['username'] = 'devapi';
                        //     $ftp_config['password'] = '@Develapi2023!';
                        //     $ftp_config['debug']    = TRUE;
                        //     $ftp_config['port']     = 21;

                        //     //Connect to the remote server
                        //     $this->ftp->connect($ftp_config);

                        //     //File path at local server
                        //     $source = './assets/arsip/'.$fileName;
                                                        
                        //     //File upload path of remote server
                        //     $destination = $fileName;   // '/var/www/webservice/public_html/repository/assets/arsip/'.
                            
                        //     //Upload file to the remote server
                        //     $this->ftp->upload($source, $destination);
                            
                        //     //Close FTP connection
                        //     $this->ftp->close();
                        // }
                    }
                }
            }else{
                $images = '';
                $filedata = [];
            }
            // Update Data
            $public =  '1';    
            if($public == NULL){
                $public = '0';
                $public_code = null;
            }else{
                $public_code = $this->m_api->unique_id();
            }
            if($images != ''){
                $images = implode(',', $images);
                $doc      = $images;
            }else{
                $doc = '';
            }
            $data_update = array(
                'yearDate'          => $this->post('doc_date'),
                'filename'          => $doc,
                'status'            => 'A',
                'isABulk'           => 'N',
                'edited_by'         => '819',         // repository@pmbs.ac.id 
                'edited_date'       => date("y-m-d H:i:s"),
                'active_until'      => date('Y-m-d H:i:s', strtotime($this->post('expired_date'))),
                'departement_owner' => '12',          // Admin Univ 
                'tipefile'          => '8',           // Arsip Adm Mahasiswa 
                'public'            => $public,
                'expired_public'    => @$this->post('exp_public'),
                'link'              => '',            // $this->post('link'),
                // 'public_code'       => $public_code
            );
            $updt_res = $this->m_api->update_docs($id_doc, $data_update);

            //utk filename
            $jumlahfile = count($filedata);
            if($filedata > 0){
                for ($i=0; $i < $jumlahfile; $i++) { 
                    $arrFiles = array('id_doc'  => $id_doc ,
                                    'filename'=> $filedata[$i]['filename'],
                                    'realname'=> $filedata[$i]['realname'],
                                    );
                    $this->m_api->insert_files($arrFiles);
                }
            }

            // $result = $this->m_api->add_doc($images, $filedata);
            if ($updt_res > 0) {            
                $this->m_api->insert_log_update($id_doc);

                $data = array(
                    'NIM'       => $this->post('nim'),
                    'id_doc'    => $id_doc,
                    'KodeArsip' => $ck_file['kode_arsip']
                );

                $result = array(
                    'status'    => 'S',
                    'code'      => '200',
                    'data'      => $data
                );

                $this->response($result, REST_Controller::HTTP_OK);
            }else{
                $result = array(
                    'status'    => 'E',
                    'code'      => '400',
                    'Message'   => 'Upload documents/files are failed.'
                );

                $this->response($result, REST_Controller::HTTP_BAD_REQUEST);
            }
            // echo json_encode($result['result']);

        }
    }

    function test_ftp_post(){
        $config['upload_path'] = './assets/arsip/';         
        $config['allowed_types'] = '*';
        // $config['max_size']      = '20000';
        $config['overwrite']     = FALSE;
        $this->load->library('upload');
        $this->upload->initialize($config);
        if($this->upload->do_upload('file')){
            //Get uploaded file information
            $upload_data = $this->upload->data();
            $fileName = $upload_data['file_name'];
            // var_dump($upload_data);

            //Load codeigniter FTP class
            $this->load->library('ftp');

            //FTP Configuration
            $ftp_config['hostname'] = '147.139.172.134';    //147.139.172.134   https://ws-dev.prasetiyamulya.ac.id
            $ftp_config['username'] = 'devapi';
            $ftp_config['password'] = '@Develapi2023!';
            $ftp_config['debug']    = TRUE;
            $ftp_config['port']     = 21;
            
            //Connect to the remote server
            $conn = $this->ftp->connect($ftp_config);

            //File path at local server
            $source = $upload_data['full_path'];
            // $source = FCPATH.'assets/arsip/'.$fileName;
            // $source = 'assets/arsip/'.$fileName;

            $destination = $fileName;
            // var_dump($source);
            if($conn){
                // print_r("Connection Success!");
                //Upload file to the remote server
                $res = $this->ftp->upload($source, $fileName);
                // var_dump($res);
                if($res){
                    print_r("Upload Success!");
                }else{
                    print_r("Failed!");
                }
                
                //Close FTP connection
                $this->ftp->close();
            }else{
                print_r("Connection Failed!");

            }

        }else{
            print_r("Upload failed!");
        }
    }
}
?>