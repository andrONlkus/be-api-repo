<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Arsip_REST_Controller.php';
use Restserver\Libraries\REST_Controller;

/**
 * Master data untuk aplikasi pemanggil API arsip.
 *   GET /api/master/categories?level=&parent=&q=
 *   GET /api/master/departements
 *   GET /api/master/tipefiles
 */
class Master extends Arsip_REST_Controller {

    function __construct()
    {
        parent::__construct();
        $this->load->model('m_archive');
    }

    public function categories_get()
    {
        $errors = [];
        $level = $this->get('level');
        if ($level !== NULL && $level !== '') {
            if (!in_array($level, ['1', '2', '3', '4'], TRUE)) $errors['level'] = 'Nilai 1-4';
        } else {
            $level = NULL;
        }
        $parent = $this->get('parent');
        if ($parent !== NULL && $parent !== '') {
            $parent = $this->to_id($parent);
            if ($parent === NULL) $errors['parent'] = 'Harus berupa ID angka';
        } else {
            $parent = NULL;
        }
        if (!empty($errors)) {
            return $this->error(REST_Controller::HTTP_UNPROCESSABLE_ENTITY, 'Validasi gagal', $errors);
        }

        $rows = $this->m_archive->get_categories($level, $parent, trim((string) $this->get('q')));
        foreach ($rows as &$r) {
            $r['id_category'] = (int) $r['id_category'];
            $r['level']       = (int) $r['level'];
            $r['id_parent']   = (int) $r['id_parent'];
            $r['doc_life']    = (int) $r['doc_life'];
            // Hanya level 2-4 yang bisa dipakai untuk upload arsip
            $r['can_upload']  = $r['level'] >= 2;
        }
        $this->success($rows);
    }

    public function departements_get()
    {
        $rows = $this->m_archive->get_departements();
        foreach ($rows as &$r) {
            $r['id_departement'] = (int) $r['id_departement'];
            $r['upload_limit']   = (int) $r['upload_limit'];   // MB
        }
        $this->success($rows);
    }

    public function tipefiles_get()
    {
        $rows = $this->m_archive->get_tipefiles();
        foreach ($rows as &$r) {
            $r['id_tipefile'] = (int) $r['id_tipefile'];
        }
        $this->success($rows);
    }
}
