<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/REST_Controller.php';
use Restserver\Libraries\REST_Controller;

/**
 * Base controller untuk API arsip umum (Archive, Master).
 * - Semua method wajib API key: header X-API-KEY, didaftarkan di $config['api_keys'].
 * - Format respons seragam: status S/E, code = HTTP status.
 */
abstract class Arsip_REST_Controller extends REST_Controller {

    // Nama aplikasi pemanggil (nilai dari $config['api_keys'])
    protected $client_app = NULL;

    // Method yang boleh mengirim key lewat query ?api_key= (untuk link download langsung)
    protected $query_key_methods = [];

    public function _remap($object_called, $arguments = [])
    {
        $key = $this->input->get_request_header('X-API-KEY', TRUE);
        if (empty($key) && in_array($object_called, $this->query_key_methods, TRUE)) {
            $key = $this->input->get('api_key', TRUE);
        }

        $this->client_app = $this->_find_client($key);
        if ($this->client_app === NULL) {
            return $this->error(REST_Controller::HTTP_UNAUTHORIZED, 'API key tidak valid atau tidak dikirim');
        }

        log_message('info', 'Arsip API ['.$this->client_app.'] '.$this->input->method(TRUE).' '.$this->uri->uri_string());
        parent::_remap($object_called, $arguments);
    }

    private function _find_client($key)
    {
        $keys = $this->config->item('api_keys');
        if (empty($key) || !is_array($keys)) {
            return NULL;
        }
        foreach ($keys as $valid_key => $app) {
            if (is_string($valid_key) && $valid_key !== '' && hash_equals($valid_key, (string) $key)) {
                return $app;
            }
        }
        return NULL;
    }

    protected function success($data, $http_code = REST_Controller::HTTP_OK, $message = 'Success')
    {
        $this->response([
            'status'  => 'S',
            'code'    => (string) $http_code,
            'Message' => $message,
            'data'    => $data,
        ], $http_code);
    }

    protected function error($http_code, $message, $errors = NULL)
    {
        $result = [
            'status'  => 'E',
            'code'    => (string) $http_code,
            'Message' => $message,
        ];
        if (!empty($errors)) {
            $result['errors'] = $errors;
        }
        $this->response($result, $http_code);
    }

    // Folder penyimpanan arsip, sama dengan Api::_arsip_path()
    protected function arsip_path()
    {
        $path = $this->config->item('arsip_path');
        if (empty($path)) {
            $path = '/mnt/ossfs/arsip/';
        } elseif ($path[0] !== '/') {
            $path = FCPATH.$path;
        }
        return rtrim($path, '/').'/';
    }

    protected function file_url($id_doc, $filename)
    {
        return base_url().'index.php/api/archive/'.$id_doc.'/file/'.rawurlencode($filename);
    }

    // Parameter angka positif (string digit), selain itu NULL
    protected function to_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : NULL;
        }
        return (is_string($value) && ctype_digit($value) && (int) $value > 0) ? (int) $value : NULL;
    }

    protected function valid_date($value, $format = 'Y-m-d')
    {
        if (!is_string($value)) {
            return FALSE;
        }
        $d = DateTime::createFromFormat('!'.$format, $value);
        return $d !== FALSE && $d->format($format) === $value;
    }
}
