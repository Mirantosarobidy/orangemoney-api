<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
  }

if (!class_exists('PayementModule')) {
    exit('Classe PayementModule introuvable!');
}

class OrangeMoney extends PayementModule
{
    protected $_html;

    public function __construct()
    {
    $this->name = 'orangemoney';
    $this->tab = 'payments_gateways';
    $this->version = '0.1.0';
    $this->author = 'Ranto';
    $this->bootstrap = true;

    parent::__construct();

    $this->displayName = $this->l('OrangeMoney');
    $this->description = $this->l('Faire Paiement par OrangeMoney');

    }

    public function install()
    {
        if (!parent::install() 
        || !$this->registerHook('displayPaymentOptions')
        || !$this->registerHook('displayPaymentReturn')
        ) {
        return false;
    }
    return true;
    }

    /**
    * Génération de paiement en ligne
    */ 
                        
    private function generatePaymentUrl() 
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

        return $this->generatePaymentUrl();
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
            error_log('Le module OrangeMoney est désactivé.');
            return;
        }

        $parameters = $this->generatePaymentUrl();

        if ($parameters == -1) {
            return;
        }

        $this->smarty->assign(['payment_url' => $parameters->payment_url]);

        $paymentApi = new PaymentOption();
        $paymentApi->setModuleName($this->name)
            ->setCallToActionText($this->l('Orange Money WEBPAY Madagascar'))
            ->setForm($this->fetch('module:orangemoney/views/templates/hook/form.tpl'))
            ->setAdditionalInformation($this->fetch('module:orangemoney/views/templates/hook/redir_api.tpl'));

        return [$paymentApi];
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
            $this->getTemplateVariables()
        );

        return $this->fetch('module:orangemoney/views/templates/hook/return.tpl');
    }

    /**
     * Configuration admin
     */
    public function generateContent()
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
        return $this->displayConfirmation($this->l('Confirmation effectuée!'));
    }

    /**
     * Formulaire de configuration admin
     */
    public function renderForm()
    {
        $formFields = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Confirmation de paiement OrangeMoney'),
                    'icon' => 'icon-cogs'
                ],
                'description' => $this->l('Vous allez confirmer le paiement par OrangeMoney'),
                'input' => $this->generateFormInputs(),
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
            'fields_value' => $this->generateConfirmationValues(),
            'languages' => $this->context->controller->generateLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$formFields]);
    }

    /**
     * Configuration formulaire de paiement
     */
    private function generateFormInputs()
    {
        return [
            [
                'type' => 'text',
                'label' => $this->l("URL pour authentifiaction OAUTH"),
                'name' => 'OAUTH_URL',
                'required' => true,
                'empty_message' => $this->l("Saisir l'URL pour authentifiaction"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("Authorization Key (CONSUMER KEY)"),
                'name' => 'CONSUMER_KEY',
                'required' => true,
                'empty_message' => $this->l("Saisir l'authorisarion (CONSUMER_KEY)"),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Transaction Initialization URL'),
                'name' => 'BASE_URL',
                'required' => true,
                'empty_message' => $this->l('Saisir la Base URL'),
            ],
            [
                'type' => 'text',
                'label' => $this->l("Transaction Status URL"),
                'name' => 'TRANSACTION_STATUS_URL',
                'required' => true,
                'empty_message' => $this->l("Saisir l'URL pour vérifier le statut de transaction"),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Token'),
                'name' => 'ACCESS_TOKEN',
                'disabled' => true,
                'empty_message' => $this->l("Ce champ va être completer automatiquement"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("Date d'Expiration du token"),
                'name' => 'TOKEN_EXPIRE',
                'disabled' => true,
                'empty_message' => $this->l("Ce champ va être completer automatiquement"),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Clé du marchant (MERCHANT KEY)'),
                'name' => 'MERCHANT_KEY',
                'required' => true,
                'empty_message' => $this->l("Saisir la Clé du Marchand"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("Devise"),
                'name' => 'CURRENCY',
                'required' => true,
                'empty_message' => $this->l("Saisir la Devise"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("URL de retour"),
                'name' => 'RETURN_URL',
                'required' => true,
                'empty_message' => $this->l("Saisir URL de retour"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("URL d'annulation"),
                'name' => 'CANCEL_URL',
                'required' => true,
                'empty_message' => $this->l("Saisir URL d'abandon"),
            ],
            [
                'type' => 'text',
                'label' => $this->l("Langue"),
                'name' => 'LANG',
                'required' => true,
                'empty_message' => $this->l("Saisir la langue"),
            ],
        ];
    }

    /**
     * Configuration des champs de valeurs
     */
    public function generateConfirmationValues()
    {
        $nameFields = [
            'OAUTH_URL',
            'BASE_URL',
            'CONSUMER_KEY',
            'MERCHANT_KEY',
            'ACCESS_TOKEN',
            'TOKEN_EXPIRE',
            'CURRENCY',
            'TRANSACTION_STATUS_URL',
            'CANCEL_URL',
            'RETURN_URL',
            'LANG',
        ];

        $values = [];

        foreach ($nameFields as $field) {
            $values[$field] = Tools::getValue($field, Configuration::get($field));
        }

        return $values;
    }

    /**
     * Configuration des informations templates
     */
    public function getTemplateVariables()
    {
        return [
            'shop_name' => $this->context->shop->name,
            'custom_var' => $this->l('My custom var value'),
            'payment_details' => $this->l('custom details'),
        ];
    }
}