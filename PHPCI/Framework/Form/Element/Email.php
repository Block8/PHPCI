<?php

namespace PHPCI\Framework\Form\Element;

use PHPCI\Framework\View;

class Email extends Text
{
    public function render($viewFile = null)
    {
        return parent::render(($viewFile ? $viewFile : 'Text'));
    }

    protected function _onPreRender(View &$view)
    {
        parent::_onPreRender($view);
        $view->type = 'email';
    }
}