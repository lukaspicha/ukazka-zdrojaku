<?php

namespace App\Presenters;

use Nette;
use App\Model;
use App\Forms;
use Nette\Application\UI\Form;
use Tracy\Debugger;

use App\Utils\MyUtils;

use Nette\Mail\Message;
use Nette\Mail\SendmailMailer;



class ValidationsPresenter extends BasePresenter
{

	/** @var ValidationModel */
	private $validationModel;

	/** @var FileModel */
	private $fileModel;

	/** @var SubvalidationModel */
	private $subvalidationModel;

	/** @var ValidatorModel */
	private $validatorModel;

	/** @var Forms\AddFilesFormFactory @inject */
	public $addFilesFormFactory;

	/** @var Forms\AddValidatorToValidationFormFactory @inject */
	public $addValidatorToValidationFormFactory;

	/** @var Forms\AddNewValidationFormFactory @inject */
	public $addNewValidationFormFactory;

	/** @var Forms\EditValidationFormFactory @inject */
	public $editValidationFormFactory;

	/** @var Forms\CancelValidationFormFactory @inject */
	public $cancelValidationFormFactory;

	/** @var Forms\AddLinkFormFactory @inject */
	public $addLinkFormFactory;

	/** @var Forms\SetDateFormFactory @inject */
	public $setDateFormFactory;

	/** @var Forms\SetDatesOnDemandFormFactory @inject */
	public $setDatesOnDemandFormFactory;

	private $database;

	/** @var ProductModel */
	private $productModel;

	/** @persistent */
	public $progresses_id = array(1,2,3,4,5);

	public function __construct(Nette\Database\Context $database, Model\ValidationModel $validationModel, Model\SubvalidationModel $subvalidationModel, Model\FileModel $fileModel, Model\ValidatorModel $validatorModel, Model\ProductModel $pm) 
	{
		$this->database = $database;
		$this->validationModel = $validationModel;
		$this->subvalidationModel = $subvalidationModel;
		$this->fileModel = $fileModel;
		$this->validatorModel = $validatorModel;
		$this->productModel = $pm;
		Debugger::enable();

	}

	public function actionDefault()
	{
		$this->template->validations = $this->validationModel->getTable();
		$validationsRs = $this->validationModel->query("select *,  (select count(subvalidations.id) from subvalidations where validations_id = V.id and demand_states_id in (3, 5)) as 'nValidators' from validations V");
		$nValidators = array();
		$percentages = array();
		foreach ($validationsRs as $row) 
		{
			$nValidators[$row->id] = $row->nValidators;
			$percentages[$row->id] = $this->getValidationProgress($row);
		}
		$this->template->nValidators = $nValidators;
		$this->template->percentages = $percentages;
		$productsRS = $this->productModel->getTable()->limit(10);
	}

	public function renderDemand($id)
	{
		$validation = $this->validationModel->getRow($id);
		if ($validation)
		{
			if($validation->validation_progresses_id == 6)
			{
				if($validation->author_id == $this->user->id)
				{
					$this->template->validation = $validation;
					$this->template->demands = $this->database->table('demands')->where('validations_id', $validation->id);
				}
				else
				{
					$this->flashMessage('K této poptávce nemáte přístup.', 'ui red message');
					$this->redirect('default');
				}
			}
			else
			{
				$this->flashMessage('Neplatné url.', 'ui red message');
				$this->redirect('default');
			}
		}
		else
		{
			$this->flashMessage('Neplatné url.', 'ui red message');
			$this->redirect('default');
		}
	}

	public function handleEvaluationDetail($id, $subID)
	{
		$this->template->evaluation = $this->database->table('subvalidations_parameters')->where('subvalidations_id', $subID);
		$this->redrawControl('subvalidationDetail');
	}

	
	private function getNFilesForValidator($subvalidationsRS)
	{
		$result = array();
		foreach ($subvalidationsRS as $row) {
			$result[$row->id] = $this->database->table('sub_files')->where('subvalidations_id', $row->id)->count('*');
		}
		return $result;
	}

	public function handleSubvalidation($id, $subID)
	{
		$this->template->subvalidationDetail = $this->subvalidationModel->getRow($subID);
		$this->template->evaluation = $this->database->table('subvalidations_parameters')->where('subvalidations_id', $subID);
		$this->template->messages = $this->database->table('messages')->where('subvalidations_id', $subID)->where('close_message', 1);
		$this->template->subFiles = $this->database->table('sub_files')->where('subvalidations_id', $this->subvalidationModel->getRow($subID)->id);
		$this->redrawControl('subvalidationSnippet');
	}

	private function getValidationProgress($validation)
	{
		$finished = $this->subvalidationModel->getTable()->where('validations_id', $validation->id)->where('subvalidation_states_id', 3)->where('demand_states_id IN', [3,5])->count('id');
		$total = $this->subvalidationModel->getTable()->where('validations_id', $validation->id)->where('subvalidation_states_id', array(1,2,3))->where('demand_states_id IN', [3,5])->count('id');
		$perc = 0;
		if($total != 0)
			$perc =  round($finished / $total * 100);
		return $perc;
	}

	public function actionCreateFromDemand($id)
	{
		$demand = $this->database->table('validations')->get($id);
		if($demand)
		{
			try
			{	
				$data = ['validation_progresses_id' => 5];
				$demand->update($data);
				$demandsRS = $this->database->table('demands')->where('validations_id', $demand->id);
				foreach ($demandsRS as $row) 
				{
					$data = ['validations_id' => $demand->id, 'validators_id' => $row->validators_id, 'subvalidation_internal_states_id' => 4, 'subvalidation_states_id' => 1, 'demand_states_id' => $row->demand_states_id];
					$subvalidation = $this->database->table('subvalidations')->insert($data);
					$hash = $subvalidation->validations_id . $subvalidation->id . $subvalidation->validators_id;
					$subvalidation->update(['hash' => sha1($hash)]);
				}
				$this->flashMessage('Koncept externí validace z poptávky byl vytvořen.', 'ui green message');
				$this->redirect('concept', $demand->id);
			}
			catch(\PDOException $ex)
			{
				$this->flashMessage($ex->getMessage(), 'ui red message');
				$this->redirect('default');
			}
		}
		else
		{
			$this->flashMessage("Neplatné url.", "ui red message");
			$this->redirect('default');
		}
	}


	public function handleSortByNameAsc()
	{
		$this->template->validations = $this->validationModel->getTable()->where('validation_progresses_id', $this->progresses_id)->order('name ASC');
		$this->redrawControl('validations');
	}
	public function handleSortByNameDesc()
	{
		$this->template->validations = $this->validationModel->getTable()->where('validation_progresses_id', $this->progresses_id)->order('name DESC');
		$this->redrawControl('validations');
	}



	public function handleAll()
	{
		$this->progresses_id = array(1,2,3,4,5);
		$this->template->validations = $this->validationModel->getTable()->where('validation_progresses_id', $this->progresses_id);
		$this->redrawControl('validations');
	}


	public function actionCloseValidation($id)
	{
		$this->setValidation($id, 3, 'Validace byla uzavřena', 2);
	}

	public function actionCancelValidation($id)
	{
		$this->setValidation($id, 2, 'Validace byla zrušena.', 3);
	}

	public function actionRemoveValidation($id)
	{
		$this->setValidation($id, 0, "Validace byla odstraněna.", 0);
	}

	public function actionReopenSubvalidation($id)
	{
		$subvalidation = $this->database->table('subvalidations')->get($id);
		if($subvalidation)
		{
			try
			{
				$values = array('subvalidation_states_id' => 2, 'subvalidation_internal_states_id' => 1);
				$subvalidation->update($values);
				$mail = NULL;
				$mailRS = $this->database->table('mails')->where('validators_id', $subvalidation->validators_id)->where('is_primary', 1);
				foreach ($mailRS as $row) 
				{
					$mail = $row->mail_adrres;
				}	
				$data = array('name' => $subvalidation->validations->name, 'url' => 'http://fwgsm1.jablotron.cz/eval/www/validations/validation/' . $subvalidation->hash);
				$this->writeReopenMail($data, $mail);
				$this->database->table('subvalidations_events')->insert(['subvalidation_internal_states_id' => 9, 'subvalidations_id' => $subvalidation->id]);
				$this->flashMessage('Externí validace byla znovu otevřena.', 'ui green message');
			}
			catch(\PDOException $ex)
			{
				$this->flashMessage($ex->getMessage(), 'ui red message');
			}
			$this->redirect('Validations:detail', $subvalidation->validations_id);
		}
		else
		{
			$this->flashMessage('Neplatné url.', 'ui red message');
			$this->redirect('default');
		}
	}

	public function actionStartValidation($id)
	{
		$parametersRS = $this->database->table('validations_parameters')->where('validations_id', $id);
		$subvalidationsRS = $this->subvalidationModel->getTable()->where('validations_id', $id);
		foreach ($subvalidationsRS as $row) 
		{
			$listParams = array();
			foreach ($parametersRS as $param) 
			{
				try
				{
					$this->database->table('subvalidations_parameters')->insert(array('mark' => NULL, 'subvalidations_id' => $row->id, 'parameters_id' => $param->parameters_id));
					array_push($listParams, $param->parameters->name);
				}
				catch(\PDOException $ex)
				{
					$this->flashMessage($ex->getMessage(), 'ui red message');
				}

			}
			if ($row->demand_states_id == NULL || $row->demand_states_id == 3)
				$this->writeMail($row, $this->getMailByValidator($row->validators->id), $listParams);			
		}
		$this->setValidation($id, 1, "Validace byla spuštěna.", 2);
	}


	private function setValidation($id, $progressId, $successMessge, $resultsID)
	{
		$validation = $this->validationModel->getRow($id);
		if($validation)
		{
			try
			{
				if($progressId == 0)
				{
					$filesRS = $this->database->table('files')->where('validations_id', $id);
					foreach ($filesRS as $value) {
						try 
						{
							if(file_exists($value->absolute_url))
								unlink($value->absolute_url);
						} 
						catch(\PDOException $ex)
						{
							$this->flashMessage($ex->getMessage(), 'ui red message');
						}
					}
					$filesRS->delete();
					$this->validationModel->deleteRow($id);
				}
				else
				{
					$validation->update(array('validation_progresses_id' => $progressId, 'real_enddate' => date('Y-m-d'), 'validation_results_id' => $resultsID));
				}
				$this->flashMessage($successMessge, 'ui green message');
				$this->redirect('default');
			}
			catch(\PDOException $ex)
			{
				$this->flashMessage($ex->getMessage(), 'ui red message');
				$this->redirect('detail#exvals', $validations_id);
			}
			
		}
		else
		{
			$this->flashMessage('Neplatné url.', 'ui red message');
			$this->redirect('default');
		}
	}

	public function createComponentSetDatesOnDemandForm()
	{
		$control = $this->setDatesOnDemandFormFactory->create(function (){});
		$demand = $this->database->table('validations')->get($this->getParameter('id'));
		$values = ['id' => $demand->id, 'expected_enddate' => date_format($demand['expected_enddate'], 'Y-m-d'), 'reply_date' => date_format($demand->reply_date, 'Y-m-d'), 'start_date' => date_format($demand->start_date, 'Y-m-d')];
		$control->setDefaults($values);
		$control['save']->onClick[] = function(Nette\Forms\Controls\SubmitButton $button) {};
		return $control;
	}
}
