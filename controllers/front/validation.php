<?php

class OrangeMoneyValidationModuleFrontController extends ModuleFrontController 
{
    /**
     * Retours de l'api de paiement
     */
    public function postProcess()
    {
        // Vérification générales 
        $cart = $this->context->cart;
        $authorized = false;

        /*
         * Vérifier si le module est active et que le panier contient in produit
         * adresse de facturation
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /** 
         * Vérification si le module de paiement est autorisé
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'orangemoney') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);

        /**
         * Vérifier un compte valide
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Token de paiement
         */
        if (!$this->context->cookie->__isset('ff_pay_token')) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Status par id
        $status = json_decode($this->getStatus());

        // Vérifier le status du paiement
        if ($status->{'status'} != "SUCCESS") {
            Tools::redirect('index.php?controller=order&step=1');
            return;
        }

        $this->context->cookie->__unset('ff_pay_token'); // Remove pay_token for security

        /**
         * Passer la commande
         */
        $this->module->validateOrder(
            (int) $this->context->cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $this->context->cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName . ", Ref: " . $status->{'txnid'},
            "OrangeMoney TxnID : " . $status->{'txnid'},
            null,
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        /**
         * Redirection vers la page de confirmation de commande
         */
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
    }

    private function getStatus()
    {
        $pay_token = $this->context->cookie->__get('ff_om_pay_token');
        $_order_id = $this->context->cookie->__get('ff_om_order_id');
        $amount = $this->context->cookie->__get('ff_om_amount');

        $data = array('amount' => $amount, 'order_id' => $_order_id, 'pay_token' => $pay_token);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, Configuration::get('TRANSACTION_STATUS_URL'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $headers = [
            'Authorization: ' . Configuration::get('ACCESS_TOKEN'),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        return $result;
    }
}