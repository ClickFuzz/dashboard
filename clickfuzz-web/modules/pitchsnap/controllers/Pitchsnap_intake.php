<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_intake extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Perfex helpers (e.g. Leads_model->log_lead_activity) call $CI->app_object_cache;
        // it's normally loaded by the Perfex base controller, so we load it here explicitly.
        $this->load->library('App_object_cache');
    }

    public function request()
    {
        if ($this->input->server('REQUEST_METHOD') === 'POST') {
            $this->_process();
            return;
        }

        // GET — render intake form
        include(FCPATH . 'modules/pitchsnap/views/intake_form.php');
    }

    private function _process()
    {
        $post = $this->input->post(null, true); // XSS-cleaned

        $errors = [];

        $name = trim($post['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (strlen($name) > 250) {
            $errors[] = 'Name must be 250 characters or fewer.';
        }

        $email = trim($post['email'] ?? '');
        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }

        $website = trim($post['website'] ?? '');
        if ($website === '') {
            $errors[] = 'Current website URL is required.';
        } elseif (!$this->_is_valid_url($website)) {
            $errors[] = 'Website must be a valid http or https URL (e.g. https://example.com).';
        }

        $phone = trim($post['phone'] ?? '');
        if ($phone === '') {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^[\d\s\-\+\(\)\.]{7,30}$/', $phone)) {
            $errors[] = 'Phone number contains invalid characters.';
        }

        // Industry validation
        $vertical_labels = [
            'hvac'               => 'HVAC',
            'plumbing'           => 'Plumbing',
            'roofing'            => 'Roofing',
            'electrical'         => 'Electrical',
            'general_contractor' => 'General Contractor',
            'remodeling'         => 'Remodeling',
            'landscaping'        => 'Landscaping',
            'tree_service'       => 'Tree Service',
            'pest_control'       => 'Pest Control',
            'garage_door'        => 'Garage Door',
            'painting'           => 'Painting',
            'flooring'           => 'Flooring',
            'concrete'           => 'Concrete',
            'fencing'            => 'Fencing',
            'pool_spa'           => 'Pool & Spa',
            'restoration'        => 'Restoration',
            'cleaning'           => 'Cleaning',
            'auto_repair'        => 'Auto Repair',
            'towing'             => 'Towing',
            'moving'             => 'Moving',
            'locksmith'          => 'Locksmith',
            'solar'              => 'Solar',
            'real_estate'        => 'Real Estate',
            'mortgage'           => 'Mortgage',
            'insurance'          => 'Insurance',
            'law_firm'           => 'Law Firm',
            'accounting'         => 'Accounting',
            'dental'             => 'Dental',
            'chiropractic'       => 'Chiropractic',
            'med_spa'            => 'Med Spa',
            'veterinary'         => 'Veterinary',
            'other'              => 'Other',
        ];

        $vertical_slug  = trim($post['vertical'] ?? '');
        $vertical_other = '';

        if ($vertical_slug === '') {
            $errors[] = 'Please select your industry.';
        } elseif (!array_key_exists($vertical_slug, $vertical_labels)) {
            $errors[] = 'Please select a valid industry.';
        } elseif ($vertical_slug === 'other') {
            $vertical_other = trim($post['vertical_other'] ?? '');
            if ($vertical_other === '') {
                $errors[] = 'Please describe your type of business.';
            } elseif (strlen($vertical_other) > 100) {
                $errors[] = 'Business type must be 100 characters or fewer.';
            }
        }

        if (!empty($errors)) {
            $this->_render_result(false, implode(' ', $errors));
            return;
        }

        // Effective vertical stored in the record and passed to the AI prompt
        $effective_vertical = ($vertical_slug === 'other' && $vertical_other !== '')
            ? $vertical_other
            : ($vertical_labels[$vertical_slug] ?? 'Local Business');

        $company     = trim($post['company']             ?? '');
        $role        = trim($post['role']                ?? '');
        $company_sz  = trim($post['company_size']        ?? '');
        $improvement = trim($post['desired_improvement'] ?? '');
        $source_name = trim($post['source']              ?? '');
        if ($source_name === '') {
            $source_name = get_option('pitchsnap_default_source') ?: 'Meta / Free HVAC Redesign';
        }

        $this->load->model('leads_model');
        $this->load->model('pitchsnap_model');

        $source_id = $this->pitchsnap_model->get_or_create_source($source_name);
        $status_id = $this->pitchsnap_model->get_default_status_id();

        $desc_lines = [];
        $desc_lines[] = 'Industry: '          . $effective_vertical;
        if ($role        !== '') $desc_lines[] = 'Role: '             . $role;
        if ($company_sz  !== '') $desc_lines[] = 'Company size: '     . $company_sz;
        if ($improvement !== '') $desc_lines[] = 'Desired improvement: ' . $improvement;
        $desc_lines[] = 'Source: '   . $source_name;
        $desc_lines[] = 'Submitted via PitchSnap intake.';
        $description = implode("\n", $desc_lines);

        $lead_data = [
            'name'        => $name,
            'company'     => $company,
            'website'     => $website,
            'email'       => $email,
            'phonenumber' => $phone,
            'description' => $description,
            'address'     => '',
            'country'     => 0,
            'status'      => $status_id,
            'source'      => $source_id,
            'assigned'    => 0,
            'is_public'   => 0,
        ];

        $lead_id = $this->leads_model->add($lead_data);

        if (!$lead_id) {
            log_activity('PitchSnap: Lead creation failed [Email: ' . $email . ']');
            $this->_render_result(false, 'Could not create the lead record. Please try again or contact support.');
            return;
        }

        $website_data = [
            'lead_id'             => $lead_id,
            'original_url'        => $website,
            'vertical'            => $effective_vertical,
            'status'              => 'pending_generation',
            'intake_role'         => $role,
            'intake_company_size' => $company_sz,
            'intake_improvement'  => $improvement,
            'addedfrom'           => 0,
        ];

        $website_id = $this->pitchsnap_model->create($website_data);

        if (!$website_id) {
            log_activity('PitchSnap: CRITICAL — website record failed after lead created [Lead ID: ' . $lead_id . ']');
            $this->_render_result(
                false,
                'Lead #' . $lead_id . ' was created but the website record could not be saved. Please notify support with Lead ID: ' . $lead_id . '.'
            );
            return;
        }

        log_activity('PitchSnap: Intake complete [Lead ID: ' . $lead_id . ', Website ID: ' . $website_id . ']');
        $this->_render_result(true, '', $lead_id, $website_id);
    }

    private function _is_valid_url($url)
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    private function _render_result($success, $message = '', $lead_id = null, $website_id = null)
    {
        include(FCPATH . 'modules/pitchsnap/views/intake_result.php');
    }
}
