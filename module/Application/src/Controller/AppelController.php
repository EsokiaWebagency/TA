<?php

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Application\Model\File;


class AppelController extends AbstractActionController
{
    private $file;

    public function __construct(File $file)
    {
        $this->file = $file;
    }

    public function indexAction()
    {
        return new ViewModel();
    }

    public function loadAction()
    {
        $file_path = ROOT_PATH . '/data/tickets_appels_201202.csv';
        if(!file_exists($file_path)) 
        {
            return new ViewModel([
                'error' => true,
                'message' => $file_path . ' not found'
            ]);
        }

        $status = $this->file->loadDataFromCSV($file_path);
        
        return new ViewModel([
            'error' => $status['error'],
            'message' => $status['message'],
            'file' => $file_path
        ]);
    }

    public function statisticAction()
    {
        return new ViewModel([
            'total_appel' => $this->file->getTotalAppelFrom('2012-02-15'),
            'total_sms' => $this->file->getTotalSMS(),
            'volumes_data' => $this->file->getTop10data(),
        ]);
    }

}
