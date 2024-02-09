<?php

if (!defined('_PS_VERSION_')) {
    exit;
  } 

class Orangemoney extends PayementModule
{
    protected $_html;

    public function __construct()
    {
    $this->name = 'orangemoney';
    $this->tab = 'payments_gateways';
    $this->version = '0.1.0';
    $this->author = 'Ranto';

    parent::__construct();

    $this->displayName = $this->l('OrangeMoney');
    $this->description = $this->l('Accepter Paiement par OrangeMoney');
    $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        if (!parent::install() 
        || !$this->registerHook('paymentOptions')
        || !$this->registerHook('paymentReturn')
        ) {
        return false;
    }
    return true;
    }

    /**
    * Génération de paiement en ligne
    */ 
                        
    private function getPaymentUrl() 
    {
        // vérification de l'accès
        if(empty(Configuration::get('ACCESS_TOKEN'))){
            $this->refreshAccesToken();
        }

        $_order_id = $this->context->cart->id."0".time();

        $data = [
            'merchant_key' => Configuration::get('MERCHANT_KEY'),
            'currency' => Configuration::get('CURRENCY', 'Ariary'),
            'order_id' => $_order_id ,
            'amount' =>  $this->context->cart->getOrderTotal(true, Cart::BOTH),
            'return_url' => Configuration::get('RETURN_URL'),
            'cancel_url' => Configuration::get('CANCEL_URL'),
            'notif_url' => Configuration::get('RETURN_URL'),
            'lang' => Configuration::get('LANG'),
            'reference' => "PAIEMENT N ".$this->context->cart->id,
        ];

        $ch = $this->initializeCurl(Configuration::get('BASE_URL'), json_encode($data));

        $result = $this->executeCurl($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($result);

        if ($result->status = 201) {
            $this->updateCookies($result, $_order_id);
            return $result;
        }

        if ($code != 401) {
            return -1;
        }

        return $this->getPaymentUrl();
    }

    private function initializeCurl($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $headers = [
            'Authorization: ' . Configuration::get('ACCESS_TOKEN'),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
        return $ch;
    }

    private function executeCurl($ch)
    {
        return curl_exec($ch);
    }

    private function refreshAccessToken()
    {
        $ch = $this->initializeCurl(Configuration::get('OAUTH_URL'), "grant_type=client_credentials");

        $result = $this->executeCurl($ch);

        if (curl_errno($ch)) {
            return -1;
        }

        curl_close($ch);
        $result = json_decode($result);

        Configuration::updateValue('ACCESS_TOKEN', $result->token_type . ' ' . $result->access_token);

        $date_end = new DateTime();
        $date_end->add(new DateInterval('PT' . $result->expires_in . 'S'));
        Configuration::updateValue('TOKEN_EXPIRE', $date_end->format('j M Y H:i'));

        return $result;
    }

    private function updateCookies($result, $_order_id)
    {
        $this->context->cookie->__set('ff_om_pay_token', $result->pay_token);
        $this->context->cookie->write();

        $this->context->cookie->__set('ff_om_order_id', $_order_id);
        $this->context->cookie->write();

        $this->context->cookie->__set('ff_om_amount', $this->context->cart->getOrderTotal(true, Cart::BOTH));
        $this->context->cookie->write();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $parameters = $this->getPaymentUrl();

        if ($parameters == -1) {
            return;
        }

        $this->smarty->assign(['payment_url' => $parameters->payment_url]);

        $apiPayment = new PaymentOption();
        $apiPayment->setModuleName($this->name)
            ->setCallToActionText($this->l('Orange Money WEBPAY Madagascar'))
            ->setForm($this->fetch('module:orangemoney/views/templates/hook/payment_api_form.tpl'))
            ->setAdditionalInformation($this->fetch('module:orangemoney/views/templates/hook/displayPaymentApi.tpl'));

        return [$apiPayment];
    }

    /**
     * Message de confirmation
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $this->smarty->assign(
            $this->getTemplateVars()
        );

        return $this->fetch('module:orangemoney/views/templates/hook/return.tpl');
    }

    /**
     * Configuration admin
     */
    public function getContent()
    {
        $this->_html .= $this->postProcess();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    /**
     * Configuration Back Office
     */
    public function postProcess()
    {
        if(Tools::isSubmit('SubmitPaymentConfiguration')) {
            $configFields = [
                'OAUTH_URL', 'BASE_URL', 'CONSUMER_KEY', 'MERCHANT_KEY', 'ACCESS_TOKEN','TOKEN_EXPIRE', 
                'CURRENCY', 'TRANSACTION_STATUS_URL', 'CANCEL_URL', 'RETURN_URL', 'LANG'
            ];

            foreach($configFields as $field) {
                Configuration::updateValue($field, Tools::getValue($field));
            }
        }
        return $this->displayConfirmation($this->l('Configuration mise à jour!'));
    }

    /**
     * Formulaire de configuration admin
     */
    public function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuration de paiement OrangeMoney'),
                    'icon' => 'icon-cogs'
                ],
                'description' => $this->l('Ici, vous configurez le paiement par OrangeMoney'),
                'input' => $this->getConfigurationFormInputs(),
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                    'class' => 'button btn btn-default pull-right'
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
        $helper->id = 'orangemoney';
        $helper->identifier = 'orangemoney';
        $helper->submit_action = 'SubmitPaymentConfiguration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fieldsForm]);
    }
} 