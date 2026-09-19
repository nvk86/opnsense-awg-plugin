<?php

namespace OPNsense\AmneziaWG;

abstract class PageControllerBase extends \OPNsense\Base\IndexController
{
    protected function templateJSIncludes()
    {
        $result = parent::templateJSIncludes();
        $result[] = '/ui/js/jquery.qrcode.js';
        $result[] = '/ui/js/qrcode.js';
        return $result;
    }

    protected function prepareView(string $section): void
    {
        $this->view->section            = $section;
        $this->view->generalForm        = $this->getForm('general');
        $this->view->formDialogInstance = $this->getForm('dialogInstance');
        $this->view->formGridInstance   = array_merge(
            $this->getFormGrid('dialogInstance'),
            ['command_width' => '160']
        );
        $this->view->formDialogServer   = $this->getForm('dialogServer');
        $this->view->formGridServer     = array_merge(
            $this->getFormGrid('dialogServer'),
            ['command_width' => '190']
        );
        $this->view->formDialogPeer     = $this->getForm('dialogPeer');
        $this->view->formGridPeer       = array_merge(
            $this->getFormGrid('dialogPeer'),
            ['command_width' => '190']
        );
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
