<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_website_needs_approval extends App_mail_template
{
    protected $for = 'staff';
    public $slug   = 'pitchsnap-website-needs-approval';

    protected $staff;
    protected $website;
    protected $lead;

    public function __construct($staff, $website, $lead)
    {
        parent::__construct();
        $this->staff   = $staff;
        $this->website = $website;
        $this->lead    = $lead;
    }

    public function build()
    {
        $company = !empty($this->lead->company) ? $this->lead->company
                 : (!empty($this->lead->name)   ? $this->lead->name : 'Unknown');

        $this->to($this->staff->email)
            ->set_merge_fields([
                '{company}'           => $company,
                '{admin_review_link}' => admin_url('pitchsnap/detail/' . (int) $this->website->id),
                '{website_id}'        => (string) (int) $this->website->id,
                '{lead_name}'         => !empty($this->lead->name) ? $this->lead->name : $company,
            ]);
    }
}
