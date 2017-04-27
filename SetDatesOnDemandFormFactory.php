<?php

namespace App\Forms;

use Nette;
use App\Model;
use Nette\Application\UI\Form;
use Tracy\Debugger;

use Nette\Mail\Message;
use Nette\Mail\SendmailMailer;
use Nette\Utils\Image;


class SetDatesOnDemandFormFactory
{
	

  	/** @var FormFactory */
	private $factory;

	public $onClick;

	private $database;

	public function __construct(FormFactory $factory, Nette\Database\Context $database)
	{
		$this->factory = $factory;
		$this->database = $database;
		Debugger::enable();
	}
	/**
	 * @return Form
	 */
	public function create(callable $onSuccess)
	{
		$form = $this->factory->create();
		
		$form->addHidden('id');
		$form->addtext('expected_enddate', 'Nový datum ukončení:')
			->setAttribute('placeholder', date('y-m-d'));
		$form->addtext('start_date', 'Nový datum spuštění ext. validace:')
			->setAttribute('placeholder', date('y-m-d'));
		$form->addtext('reply_date', 'Nový datum vyjádřední ext.validátorů:')
			->setAttribute('placeholder', date('y-m-d'));

		$form->addCheckbox('notificate', 'Notifikovat validátory (Pouze ti co mají zobrazeno)')
			->setAttribute('class', 'ui checkbox');

		$form->addSubmit('save', 'Upravit')
			->onClick[] = [$this, 'setDate'];

     	return $form;
	}

	//Chci do Db uložit Y-m-d 23:59:00
	private function datePlusOneday($input)
	{
		$result = new \DateTime($input);
		return $result->modify('+1 day')->modify("-1 minute");
	}

	public function setDate($button)
	{
		$form = $button->getForm();
		$values = $form->getValues();
		$validation = $this->database->table('validations')->get($values->id);
		Debugger::barDump($values);
		if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $values->expected_enddate)) // netřeba, kontrola pře js před odesláním
    	{
        	try
        	{
        		$data = ['expected_enddate' => $this->datePlusOneday($values->expected_enddate), 'reply_date' => $this->datePlusOneday($values->reply_date), 'start_date' => $values->start_date];
				$validation->update($data);
				if($values->notificate)
				{	
					$data['name'] = $validation->name;
					$clients = $this->getValidatorWhereDemandRunning($validation->id);
					foreach ($clients as $client) 
					{
						Debugger::barDump($data);
						$this->writeMail($data, $client);
					}
				}
				$form->presenter->flashMessage('Datumy pro poptávky byly editovány.', 'ui green message');
        	}
        	catch(\PDOException $ex)
			{
				$form->presenter->flashMessage($ex->getMessage(), 'ui red message');
			}
    	}
    	else
    	{
        	$form->presenter->flashMessage('Neplatné datum.', 'ui red message');
    	}
		
		$form->presenter->redirect('this');
	}

	private function getValidatorWhereDemandRunning($validations_id)
	{
		$demandsRS = $this->database->table('demands')->where('validations_id', $validations_id)->where('demand_states_id', 2);
		$mails = array();
		foreach ($demandsRS as $row) 
		{
			$mailsRS = $this->database->table('mails')->where('validators_id', $row->validators_id)->where('is_primary', 1);
			foreach ($mailsRS as $mail) 
			{
				array_push($mails, $mail->mail_adrres);
			}
		}	
		return $mails;
	}

	private function writeMail($data, $client)
	{
		$latte = new \Latte\Engine;
        $mail = new Message;
        $mailer = new Nette\Mail\SmtpMailer(['host' => 'smtp.jablotron.cz']);
        $subject = "Změna datumů na poptávku " . $data['name'] . " - Jablotron Alarms a.s.";
        $mail->setFrom('eVAL <eval@jablotron.cz>')
            ->setSubject($subject)
            ->setHtmlBody($latte->renderToString('/var/www/app/pil/app/devel/eval/app/presenters/templates/Validations/setdatedemandmail.latte', $data));
        $mail->addTo($client);
        $mailer->send($mail);
	}

}

