<?php

namespace PHPCI\Controller;
use b8,
	PHPCI\Model\Build,
	PHPCI\Model\Project,
	b8\Form,
	b8\Registry;

class ProjectController extends b8\Controller
{
	public function init()
	{
		$this->_buildStore		= b8\Store\Factory::getStore('Build');
		$this->_projectStore	= b8\Store\Factory::getStore('Project');
	}

	public function view($projectId)
	{
		$project		= $this->_projectStore->getById($projectId);
		$page			= $this->getParam('p', 1);
		$builds			= $this->getLatestBuildsHtml($projectId, (($page - 1) * 10));

		$view			= new b8\View('Project');
		$view->builds	= $builds[0];
		$view->total	= $builds[1];
		$view->project	= $project;
		$view->page		= $page;

		return $view->render();
	}

	public function build($projectId)
	{
		$build = new Build();
		$build->setProjectId($projectId);
		$build->setCommitId('Manual');
		$build->setStatus(0);
		$build->setBranch('master');
		$build->setCreated(new \DateTime());

		$build = $this->_buildStore->save($build);

		header('Location: /build/view/' . $build->getId());
	}

	public function delete($id)
	{
		if(!Registry::getInstance()->get('user')->getIsAdmin())
		{
			throw new \Exception('You do not have permission to do that.');
		}

		$project	= $this->_projectStore->getById($id);
		$this->_projectStore->delete($project);

		header('Location: /');
	}

	public function builds($projectId)
	{
		$builds = $this->getLatestBuildsHtml($projectId);
		die($builds[0]);
	}

	protected function getLatestBuildsHtml($projectId, $start = 0)
	{
		$builds			= $this->_buildStore->getWhere(array('project_id' => $projectId), 10, $start, array(), array('id' => 'DESC'));
		$view			= new b8\View('BuildsTable');
		$view->builds	= $builds['items'];

		return array($view->render(), $builds['count']);
	}

	public function add()
	{
		if(!Registry::getInstance()->get('user')->getIsAdmin())
		{
			throw new \Exception('You do not have permission to do that.');
		}

		$method	= Registry::getInstance()->get('requestMethod');

		if($method == 'POST')
		{
			$values = $this->getParams();
			$pub = null;
		}
		else
		{
			$tempPath = sys_get_temp_dir() . '/tmp/';
			$id = $tempPath . md5(microtime(true));
			if (!is_dir($tempPath)) {
				mkdir($tempPath);
			}
			shell_exec('ssh-keygen -q -t rsa -b 2048 -f '.$id.' -N "" -C "deploy@phpci"');

			$pub = file_get_contents($id . '.pub');
			$prv = file_get_contents($id);

			$values = array('key' => $prv);
		}

		$form	= $this->projectForm($values);

		if($method != 'POST' || ($method == 'POST' && !$form->validate()))
		{
			$view			= new b8\View('ProjectForm');
			$view->type		= 'add';
			$view->project	= null;
			$view->form		= $form;
			$view->key		= $pub;

			return $view->render();
		}

		$values				= $form->getValues();
		$values['git_key']	= $values['key'];

		$project = new Project();
		$project->setValues($values);

		$project = $this->_projectStore->save($project);

		header('Location: /project/view/' . $project->getId());
		die;
	}

	public function edit($id)
	{
		if(!Registry::getInstance()->get('user')->getIsAdmin())
		{
			throw new \Exception('You do not have permission to do that.');
		}
		
		$method		= Registry::getInstance()->get('requestMethod');
		$project	= $this->_projectStore->getById($id);

		if($method == 'POST')
		{
			$values = $this->getParams();
		}
		else
		{
			$values			= $project->getDataArray();
			$values['key']	= $values['git_key'];
		}

		$form	= $this->projectForm($values, 'edit/' . $id);

		if($method != 'POST' || ($method == 'POST' && !$form->validate()))
		{
			$view			= new b8\View('ProjectForm');
			$view->type		= 'edit';
			$view->project	= $project;
			$view->form		= $form;
			$view->key		= null;

			return $view->render();
		}

		$values				= $form->getValues();
		$values['git_key']	= $values['key'];

		$project->setValues($values);
		$project = $this->_projectStore->save($project);

		header('Location: /project/view/' . $project->getId());
		die;
	}

	protected function projectForm($values, $type = 'add')
	{
		$form = new Form();
		$form->setMethod('POST');
		$form->setAction('/project/' . $type);
		$form->addField(new Form\Element\Csrf('csrf'));

		$field = new Form\Element\Text('title');
		$field->setRequired(true);
		$field->setLabel('Project Title');
		$field->setClass('span4');
		$form->addField($field);

		$field = new Form\Element\Select('type');
		$field->setRequired(true);
		$field->setOptions(array('github' => 'Github', 'bitbucket' => 'Bitbucket'));
		$field->setLabel('Where is your project hosted?');
		$field->setClass('span4');
		$form->addField($field);

		$field = new Form\Element\Text('reference');
		$field->setRequired(true);
		$field->setPattern('[a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+');
		$field->setLabel('Repository Name on Github / Bitbucket (e.g. block8/phpci)');
		$field->setClass('span4');
		$form->addField($field);

		$field = new Form\Element\TextArea('key');
		$field->setRequired(false);
		$field->setLabel('Private key to use to access repository (leave blank to use anonymous HTTP repository access)');
		$field->setClass('span7');
		$field->setRows(6);
		$form->addField($field);

		$field = new Form\Element\Submit();
		$field->setValue('Save Project');
		$field->setClass('btn-success');
		$form->addField($field);

		$form->setValues($values);
		return $form;
	}
}