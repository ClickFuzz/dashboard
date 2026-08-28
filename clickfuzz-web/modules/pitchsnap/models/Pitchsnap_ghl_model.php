<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_ghl_model extends CI_Model
{
    private $table;

    public function __construct()
    {
        parent::__construct();
        $this->table = db_prefix() . 'pitchsnap_ghl_locations';
    }

    public function get_by_site($site_id)
    {
        return $this->db->where('site_id', (int) $site_id)->get($this->table)->row();
    }

    public function mark_connected($site_id, $location_id, $location_name)
    {
        $now      = date('Y-m-d H:i:s');
        $existing = $this->get_by_site($site_id);
        $data = [
            'ghl_location_id'   => $location_id,
            'ghl_location_name' => $location_name,
            'status'            => 'connected',
            'last_error'        => null,
            'last_verified_at'  => $now,
        ];

        if ($existing) {
            $data['updated_at'] = $now;
            $this->db->where('site_id', (int) $site_id)->update($this->table, $data);
        } else {
            $data['site_id']    = (int) $site_id;
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $this->db->insert($this->table, $data);
        }
    }
}
