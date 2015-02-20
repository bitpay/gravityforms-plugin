<?php

/**
 * @license Copyright 2011-2014 BitPay Inc., MIT License 
 * see https://github.com/bitpay/gravityforms-plugin/blob/master/LICENSE
 */

/**
 * Class for managing form data
 */
class GFBitPayFormData
{
    public $price    = 0;
    public $total    = 0;
    public $shipping = 0;

    public $productName        = '';
    public $productDescription = '';
    public $buyerName          = '';
    public $buyerAddress1      = '';
    public $buyerAddress2      = '';
    public $buyerCity          = '';
    public $buyerState         = '';
    public $buyerZip           = '';
    public $buyerEmail         = '';
    public $buyerPhone         = '';
    public $buyerCountry       = '';
    public $address            = '';

    private $isLastPageFlag        = false;
    private $hasPurchaseFieldsFlag = false;

    /**
     * initialise instance
     * @param array $form
     */
    public function __construct(&$form)
    {
        if (false === isset($form) || true === empty($form)) {
            error_log('[ERROR] In GFBitPayFormData::__construct(): Missing or invalid $form parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $form parameter in the GFBitPayFormData::__construct() function.');
        }

        // check for last page
        $current_page = GFFormDisplay::get_source_page($form['id']);
        $target_page  = GFFormDisplay::get_target_page($form, $current_page, rgpost('gform_field_values'));

        $this->isLastPageFlag = ($target_page == 0);

        // load the form data
        $this->loadForm($form);
    }

    /**
     * load the form data we care about from the form array
     * @param array $form
     */
    private function loadForm(&$form)
    {
        if (false === isset($form) || true === empty($form)) {
            error_log('[ERROR] In GFBitPayFormData::loadForm(): Missing or invalid $form parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $form parameter in the GFBitPayFormData::loadForm() function.');
        }

        foreach ($form['fields'] as &$field) {
            $id = $field['id'];

            switch (RGFormsModel::get_input_type($field)) {
                case 'name':
                    if (true === empty($this->buyerName)) {
                        $this->buyerName = rgpost("input_{$id}");
                    }
                    break;

                case 'email':
                    if (true === empty($this->buyerEmail)) {
                        $this->buyerEmail = rgpost("input_{$id}");
                    }
                    break;

                case 'phone':
                    if (true === empty($this->buyerPhone)) {
                        $this->buyerPhone = rgpost("input_{$id}");
                    }
                    break;

                case 'address':
                    if (true === empty($this->address)) {
                        $parts = array();

                        $this->buyerAddress1 = trim(rgpost("input_{$id}_1"));
                        $this->buyerAddress2 = trim(rgpost("input_{$id}_2"));
                        $this->buyerCity     = trim(rgpost("input_{$id}_3"));
                        $this->buyerState    = trim(rgpost("input_{$id}_4"));
                        $this->buyerZip      = trim(rgpost("input_{$id}_5"));
                        $this->buyerCountry  = trim(rgpost("input_{$id}_6"));

                        $parts = array($this->buyerAddress1, $this->buyerAddress2, $this->buyerCity, $this->buyerState, $this->buyerZip);

                        $this->address = implode(', ', array_filter($parts, 'strlen'));
                    }
                    break;

                case 'singleproduct':
                    $this->productName          .= (rgpost("input_{$id}_1"));
                    $this->price                 = GFCommon::to_number(rgpost("input_{$id}_2"));
                    $this->productDescription    = $field['description'];
                    $this->hasPurchaseFieldsFlag = true;
                    break;
                    
                case 'total':
                    $this->total                 = GFCommon::to_number(rgpost("input_{$id}"));
                    $this->hasPurchaseFieldsFlag = true;
                    break;

                default:
                    // check for shipping field
                    if ($field['type'] == 'shipping') {
                        $this->shipping             += self::getShipping($form, $field);
                        $this->hasPurchaseFieldsFlag = true;
                    }

                    // check for product field
                    if (GFCommon::is_product_field($field['type'])) {
                        $this->price                += self::getProductPrice($form, $field);
                        $this->hasPurchaseFieldsFlag = true;

                        if ($field['type'] != 'shipping') {
                            $this->productName .= ' // '.$field['label'];
                        }
                    }
                    break;
            }
        }

        // if form didn't pass the total, pick it up from calculated amount
        if ($this->total == 0) {
            $this->total = $this->price + $this->shipping;
        }
    }

    /**
     * extract the price from a product field, and multiply it by the quantity
     * @return float
     */
    private static function getProductPrice($form, $field)
    {
        if (false === isset($form) || true === empty($form)) {
            error_log('[ERROR] In GFBitPayFormData::getProductPrice(): Missing or invalid $form parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $form parameter in the GFBitPayFormData::getProductPrice() function.');
        }

        if (false === isset($field) || true === empty($field)) {
            error_log('[ERROR] In GFBitPayFormData::getProductPrice(): Missing or invalid $field parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $field parameter in the GFBitPayFormData::getProductPrice() function.');
        }

        $price = 0;
        $qty   = 0;

        $isProduct = false;

        $id = $field['id'];

        if (!RGFormsModel::is_field_hidden($form, $field, array())) {
            $lead_value = rgpost("input_{$id}");
            $qty_field  = GFCommon::get_product_fields_by_type($form, array('quantity'), $id);

            $qty = sizeof($qty_field) > 0 ? rgpost("input_{$qty_field[0]['id']}") : 1;

            switch ($field["inputType"]) {
                case 'singleproduct':
                    $price     = GFCommon::to_number(rgpost("input_{$id}_2"));
                    $qty       = GFCommon::to_number(rgpost("input_{$id}_3"));
                    $isProduct = true;
                    break;

                case 'hiddenproduct':
                    $price     = GFCommon::to_number($field["basePrice"]);
                    $isProduct = true;
                    break;

                case 'price':
                    $price     = GFCommon::to_number($lead_value);
                    $isProduct = true;
                    break;

                default:
                    // handle drop-down lists
                    if (false === empty($lead_value)) {
                        list($name, $price) = rgexplode('|', $lead_value, 2);
                        $isProduct = true;
                    }
                    break;
            }

            // pick up extra costs from any options
            if ($isProduct == true) {
                $options = GFCommon::get_product_fields_by_type($form, array('option'), $id);

                foreach ($options as $option) {
                    if (!RGFormsModel::is_field_hidden($form, $option, array())) {
                        $option_value = rgpost("input_{$option['id']}");

                        if (true === is_array(rgar($option, 'inputs'))) {
                            foreach ($option['inputs'] as $input) {
                                $input_value = rgpost('input_' . str_replace('.', '_', $input['id']));
                                $option_info = GFCommon::get_option_info($input_value, $option, true);

                                if (false === empty($option_info)) {
                                    $price += GFCommon::to_number(rgar($option_info, 'price'));
                                }
                            }
                        } else if (false === empty($option_value)) {
                            $option_info = GFCommon::get_option_info($option_value, $option, true);
                            $price      += GFCommon::to_number(rgar($option_info, 'price'));
                        }
                    }
                }
            }

            $price *= $qty;
        }

        return $price;
    }

    /**
     * extract the shipping amount from a shipping field
     * @return float
     */
    private static function getShipping($form, $field)
    {
        if (false === isset($form) || true === empty($form)) {
            error_log('[ERROR] In GFBitPayFormData::getShipping(): Missing or invalid $form parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $form parameter in the GFBitPayFormData::getShipping() function.');
        }

        if (false === isset($field) || true === empty($field)) {
            error_log('[ERROR] In GFBitPayFormData::getShipping(): Missing or invalid $field parameter.');
            throw new \Exception('An error occurred in the BitPay Payment plugin: Missing or invalid $field parameter in the GFBitPayFormData::getShipping() function.');
        }

        $shipping = 0;

        $id = $field['id'];

        if (!GFFormsModel::is_field_hidden($form, $field, array())) {
            $value = rgpost("input_{$id}");

            if (false === empty($value) && $field["inputType"] != 'singleshipping') {
                // drop-down list / radio buttons
                list($name, $value) = rgexplode('|', $value, 2);
            }

            $shipping = GFCommon::to_number($value);
        }

        return $shipping;
    }

    /**
     * check whether we're on the last page of the form
     * @return boolean
     */
    public function isLastPage()
    {
        return $this->isLastPageFlag;
    }

}
