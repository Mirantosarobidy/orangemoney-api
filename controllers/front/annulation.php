<?php

class OrangeMoneyAnnulationModuleFrontController extends ModuleFrontController 
{
    public function postProcess()
    {
        Tools::redirect('index.php?controller=order&step=1');
    }
}