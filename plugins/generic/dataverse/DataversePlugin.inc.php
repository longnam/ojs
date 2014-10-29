<?php
/**
 * @file plugins/generic/dataverse/DataversePlugin.inc.php
 *
 * Copyright (c) 2013-2014 Simon Fraser University Library
 * Copyright (c) 2003-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class DataversePlugin
 * @ingroup plugins_generic_dataverse
 *
 * @brief dataverse plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('plugins.generic.dataverse.classes.DataversePackager');

require('lib/pkp/lib/swordappv2/swordappclient.php');

// HTTP status codes
define('DATAVERSE_PLUGIN_HTTP_STATUS_OK',         200);
define('DATAVERSE_PLUGIN_HTTP_STATUS_CREATED',    201);
define('DATAVERSE_PLUGIN_HTTP_STATUS_NO_CONTENT', 204);

// Dataverse field delimiters
define('DATAVERSE_PLUGIN_TOU_POLICY_SEPARATOR', '---');
define('DATAVERSE_PLUGIN_SUBJECT_SEPARATOR', ';');

// Default format of publication citation in dataset metadata
define('DATAVERSE_PLUGIN_CITATION_FORMAT_APA', 'APA');

// Study release options
define('DATAVERSE_PLUGIN_RELEASE_ARTICLE_ACCEPTED',  0x01);
define('DATAVERSE_PLUGIN_RELEASE_ARTICLE_PUBLISHED', 0x02);

// Notification types
define('NOTIFICATION_TYPE_DATAVERSE_STUDY_CREATED',  NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001001);
define('NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED',  NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001002);
define('NOTIFICATION_TYPE_DATAVERSE_FILE_ADDED',     NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001003);
define('NOTIFICATION_TYPE_DATAVERSE_FILE_DELETED',   NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001004);
define('NOTIFICATION_TYPE_DATAVERSE_STUDY_DELETED',  NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001005);
define('NOTIFICATION_TYPE_DATAVERSE_STUDY_RELEASED', NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001006);
define('NOTIFICATION_TYPE_DATAVERSE_UNRELEASED',     NOTIFICATION_TYPE_PLUGIN_BASE + 0x0001007);

class DataversePlugin extends GenericPlugin {

	/**
	 * @see LazyLoadPlugin::register()
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if ($success && $this->getEnabled()) {
			// Dataverse Study objects
			$this->import('classes.DataverseStudyDAO');
			$dataverseStudyDao = new DataverseStudyDAO($this->getName());
			$returner =& DAORegistry::registerDAO('DataverseStudyDAO', $dataverseStudyDao);

			// Files associated with Dataverse studies
			$this->import('classes.DataverseFileDAO');			
			$dataverseFileDao = new DataverseFileDAO($this->getName());			 
			$returner =& DAORegistry::registerDAO('DataverseFileDAO', $dataverseFileDao);
					
			// Handler for public (?) access to Dataverse-related information (i.e., terms of Use)
			HookRegistry::register('LoadHandler', array(&$this, 'setupPublicHandler'));
			// Add data citation to submissions & reading tools	 
			HookRegistry::register('TemplateManager::display', array(&$this, 'handleTemplateDisplay'));
			// Add data citation to article landing page
			HookRegistry::register('Templates::Article::MoreInfo', array(&$this, 'addDataCitationArticle'));
			// Enable TinyMCEditor in textarea fields
			HookRegistry::register('TinyMCEPlugin::getEnableFields', array(&$this, 'getTinyMCEEnabledFields'));
			// Include data policy in About page
			HookRegistry::register('Templates::About::Index::Policies', array(&$this, 'addPolicyLinks'));
			
			// Add data publication options to author submission suppfile form: 
			HookRegistry::register('Templates::Author::Submit::SuppFile::AdditionalMetadata', array(&$this, 'suppFileAdditionalMetadata'));
			HookRegistry::register('authorsubmitsuppfileform::initdata', array(&$this, 'suppFileFormInitData'));
			HookRegistry::register('authorsubmitsuppfileform::readuservars', array(&$this, 'suppFileFormReadUserVars'));
			HookRegistry::register('authorsubmitsuppfileform::execute', array(&$this, 'authorSubmitSuppFileFormExecute'));
			
			// Add Dataverse deposit options to suppfile form for completed submissions
			HookRegistry::register('Templates::Submission::SuppFile::AdditionalMetadata', array(&$this, 'suppFileAdditionalMetadata'));
			HookRegistry::register('suppfileform::initdata', array(&$this, 'suppFileFormInitData'));
			HookRegistry::register('suppfileform::readuservars', array(&$this, 'suppFileFormReadUserVars'));
			HookRegistry::register('suppfileform::execute', array(&$this, 'suppFileFormExecute'));
			
			// Notify ArticleDAO of article metadata field (external data citation) in suppfile form
			HookRegistry::register('articledao::getAdditionalFieldNames', array(&$this, 'articleMetadataFormFieldNames'));
			
			// Validate suppfile forms: warn if Dataverse deposit selected but no file uploaded
			HookRegistry::register('authorsubmitsuppfileform::Constructor', array(&$this, 'suppFileFormConstructor'));
			HookRegistry::register('suppfileform::Constructor', array(&$this, 'suppFileFormConstructor'));	
			
			// Metadata form: update cataloguing information. Prevent update if study locked.
			HookRegistry::register('metadataform::Constructor', array(&$this, 'metadataFormConstructor'));
			HookRegistry::register('metadataform::execute', array(&$this, 'metadataFormExecute'));
			
			// Handle suppfile insertion: prevent duplicate insertion of a suppfile
			HookRegistry::register('suppfiledao::_insertsuppfile', array(&$this, 'handleSuppFileInsertion'));
			// Handle suppfile deletion: only necessary for completed submissions
			HookRegistry::register('suppfiledao::_deletesuppfilebyid', array(&$this, 'handleSuppFileDeletion'));
			// Add form validator to check whether submission includes data files 
			HookRegistry::register('authorsubmitstep4form::Constructor', array(&$this, 'addAuthorSubmitFormValidator'));
			// Create study for author submissions
			HookRegistry::register('Author::SubmitHandler::saveSubmit', array(&$this, 'handleAuthorSubmission'));
			// Release or delete studies according to editor decision
			HookRegistry::register('SectionEditorAction::unsuitableSubmission', array(&$this, 'handleUnsuitableSubmission'));
			HookRegistry::register('SectionEditorAction::recordDecision', array(&$this, 'handleEditorDecision'));
			// Release studies on article publication
			HookRegistry::register('articledao::_updatearticle', array(&$this, 'handleArticleUpdate'));
			
			// Get content for plugin notifications
			HookRegistry::register('NotificationManager::getNotificationContents', array(&$this, 'getNotificationContents'));
		}
		return $success;
	}

	/**
	 * @see PKPPlugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.dataverse.displayName');
	}

	/**
	 * @see PKPPlugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.dataverse.description');
	}

	/**
	 * @see PKPPlugin::getInstallSchemaFile()
	 */
	function getInstallSchemaFile() {
		return $this->getPluginPath() . '/schema.xml';
	}

	/**
	 * Get page handler path for this plugin.
	 * @return string Path to plugin's page handler
	 */
	function getHandlerPath() {
		return $this->getPluginPath() . '/pages/';
	}
	
	/**
	 * @see PKPPlugin::getTemplatePath()
	 */
	function getTemplatePath() {
		return parent::getTemplatePath() . 'templates/';
	}	 
	
	/**
	 * @see GenericPlugin::getManagementVerbs()
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('connect', __('plugins.generic.dataverse.settings.connect'));
			$verbs[] = array('select', __('plugins.generic.dataverse.settings.selectDataverse')); 
			$verbs[] = array('settings', __('plugins.generic.dataverse.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * @see GenericPlugin::manage()
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) return false;

		$templateMgr =& TemplateManager::getManager();
		$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
		$journal =& Request::getJournal();
		
		switch ($verb) {
			case 'connect':
				$this->import('classes.form.DataverseAuthForm');
				$form = new DataverseAuthForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						 Request::redirect(null, 'manager', 'plugin', array('generic', $this->getName(), 'select'));
						return false;
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				return true;
			case 'select':
				$this->import('classes.form.DataverseSelectForm');
				$form = new DataverseSelectForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						 Request::redirect(null, 'manager', 'plugin', array('generic', $this->getName(), 'settings'));
						return false;
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				return true;
			case 'settings':
				$this->import('classes.form.SettingsForm');
				$form = new SettingsForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, 'manager', 'plugin', array('generic'));
						return false;
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				return true;
        
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}

	/**
	 * @see PKPPlugin::smartyPluginUrl()
	 */
	function smartyPluginUrl($params, &$smarty) {
		$path = array($this->getCategory(), $this->getName());
		if (is_array($params['path'])) {
			$params['path'] = array_merge($path, $params['path']);
		} elseif (!empty($params['path'])) {
			$params['path'] = array_merge($path, array($params['path']));
		} else {
			$params['path'] = $path;
		}

		if (!empty($params['id'])) {
			$params['path'] = array_merge($params['path'], array($params['id']));
			unset($params['id']);
		}
		return $smarty->smartyUrl($params, $smarty);
	}
	
	/**
	 * Hook callback: register pages to display terms of use & data policy
	 * @see PKPPageRouter::route()
	 */
	function setupPublicHandler($hookName, $params) {
		$page =& $params[0];
		if ($page == 'dataverse') {
			$op =& $params[1];
			if ($op) {
				$publicPages = array(
					'index',
					'dataAvailabilityPolicy',
					'termsOfUse',
				);

				if (in_array($op, $publicPages)) {
					define('HANDLER_CLASS', 'DataverseHandler');
					define('DATAVERSE_PLUGIN_NAME', $this->getName());
					AppLocale::requireComponents(LOCALE_COMPONENT_APPLICATION_COMMON);
					$handlerFile =& $params[2];
					$handlerFile = $this->getHandlerPath() . 'DataverseHandler.inc.php';
				}
			}
		}
	}	 
	
	/**
	 * Hook callback: register output filter to add data citation to submission
	 * summaries; add data citation to reading tools' suppfiles and metadata views.
	 * @see TemplateManager::display()
	 */
	function handleTemplateDisplay($hookName, $args) {
		$templateMgr =& $args[0];
		$template =& $args[1];

		switch ($template) {
			case $this->getTemplatePath() .'/termsOfUse.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'termsOfUseOutputFilter'));
				break;
			case 'author/submission.tpl':			 
			case 'sectionEditor/submission.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'submissionOutputFilter'));
				break;
			case 'rt/metadata.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'rtMetadataOutputFilter'));
				break;
			case 'rt/suppFiles.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'rtSuppFilesOutputFilter'));
				break;
			case 'rt/suppFileView.tpl':
				$templateMgr->register_outputfilter(array(&$this, 'rtSuppFileViewOutputFilter'));
				break;
		}
		return false;
	}
	
	/**
	 * Output filter alters title in Reading Tools' header template, re-used to 
	 * display Dataverse Terms of Use
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string Filtered output
	 */
	function termsOfUseOutputFilter($output, &$templateMgr) {
		$title = '<title>'. __('rt.readingTools') .'</title>';
		$titleIndex = strpos($output, $title);
		if ($titleIndex !== false) {
			$output = str_replace($title, '<title>'. __('plugins.generic.dataverse.termsOfUse.dataverse') .': '. __('plugins.generic.dataverse.termsOfUse.title') .'</title>', $output);
		}
		$header = __('rt.readingTools') .'</h1>';
		$headerIndex = strpos($output, $header);
		if ($headerIndex !== false) {
			$output = str_replace($header, __('plugins.generic.dataverse.termsOfUse.dataverse') .'</h1>', $output);
		}
		$templateMgr->unregister_outputfilter('termsOfUseOutputFilter');
		return $output;
	}
	
	/**
	 * Output filter adds Dataverse or external data citation and removes
	 * local download links for suppfiles deposited in Dataverse.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string Filtered output
	 */
	function rtMetadataOutputFilter($output, &$templateMgr) {
		$article =& $templateMgr->get_template_vars('article');
		$currentJournal =& $templateMgr->get_template_vars('currentJournal');		
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');		
		$study =& $dataverseStudyDao->getStudyBySubmissionId($article->getId());
		
		// Add data citation, if study exists or if external data citation given
		$dataCitation = isset($study) ? 
						$this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri()) :
						$article->getLocalizedData('externalDataCitation');
		
		if ($dataCitation) {
			$suppFileLabel = '<td>'. __('rt.metadata.pkp.suppFiles') .'</td>';
			$suppFileLabelIndex = strpos($output, $suppFileLabel);
			if ($suppFileLabelIndex !== false) {
				$newOutput = substr($output, 0, $suppFileLabelIndex);
				$newOutput .= '<td>'. __('plugins.generic.dataverse.dataCitation') .'</td>';
				$newOutput .= '<td>'. String::stripUnsafeHtml($dataCitation) .'</td>';
				$newOutput .= '</tr>';
				$newOutput .= '<tr valign="top">';
				$newOutput .= '<td>13.</td>';
				$newOutput .= '<td>'. __('rt.metadata.dublinCore.relation') .'</td>';
				$newOutput .= substr($output, $suppFileLabelIndex);
				$output = $newOutput;
			}
		}
			
		// Don't display public links to supp files that have been deposited in Dataverse
		$suppFiles = $article->getSuppFiles();		
		if (isset($study) && !empty($suppFiles)) {
			$suppFileOutput = '';
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');

			foreach ($article->getSuppFiles() as $suppFile) {
				$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($suppFile->getId(), $article->getId());
				if (isset($dvFile)) { 
					// File is in Dataverse. 
					$suppFileOutput .= $templateMgr->smartyEscape($suppFile->getSuppFileTitle()) . ' ';
					$suppFileOutput .= '<a href="'. $study->getPersistentUri() .'" target="_new" class="action">';
					$suppFileOutput .= __('plugins.generic.dataverse.suppFiles.view');
					$suppFileOutput .= '</a><br/>';
				}
				else {
					$params = array(
							'page' => 'article',
							'op'   => 'downloadSuppFile',
							'path' => array($article->getId(), $suppFile->getBestSuppFileId($currentJournal))
						);
					$suppFileOutput .= '<a href="'. $templateMgr->smartyUrl($params, $templateMgr) .'">'. $templateMgr->smartyEscape($suppFile->getSuppFileTitle()) .'</a> ';
					$suppFileOutput .= '('. $suppFile->getNiceFileSize() .')<br />';
				}
			} // end foreach($suppFile)

			// Match table row up to suppfile list
			$preMatch = '<tr valign="top">\s*';
			$preMatch .= '<td>13.<\/td>\s*';
			$preMatch .= '<td>'. preg_quote(__('rt.metadata.dublinCore.relation'), '/') .'<\/td>\s*';
			$preMatch .= '<td>'. preg_quote(__('rt.metadata.pkp.suppFiles'), '/') .'<\/td>\s*';
			$preMatch .= '<td>';

			// Match table row following suppfile list
			$postMatch .= '<\/td>\s*<\/tr>';

			if ($suppFileOutput) {
				// Replace with edited list of suppfiles not in Dataverse
				$output = preg_replace("/($preMatch).*?($postMatch)/s", "$1${suppFileOutput}$2", $output);
			}
			else {
				// All suppfiles are in Dataverse. Remove table row.
				$output = preg_replace("/($preMatch).*?($postMatch)/s", "", $output);
			}
		}  // end if (article has suppfiles in Dataverse)
		$templateMgr->unregister_outputfilter('rtMetadataOutputFilter');
		return $output;
	}
	
	/**
	 * Output filter. If suppfile is in Dataverse, filter replaces link to view
	 * or download suppfiles from OJS with link to dataset in Dataverse.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string Filtered output
	 */
	function rtSuppFilesOutputFilter($output, &$templateMgr) {
		$article =& $templateMgr->get_template_vars('article');
		$currentJournal =& $templateMgr->get_template_vars('currentJournal');		
		$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');				
		$study =& $dvStudyDao->getStudyBySubmissionId($article->getId());
		if (isset($study)) {
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
			foreach ($article->getSuppFiles() as $suppFile) {
				$dvFile = $dvFileDao->getDataverseFileBySuppFileId($suppFile->getId(), $article->getId());
				if (isset($dvFile)) {
					// Find and replace local download link w/ dataset URI 
					$params = array(
							'page' => 'article',
							'op'   => 'downloadSuppFile',
							'path' => array($article->getBestArticleId(), $suppFile->getBestSuppFileId($currentJournal))
					);
					$suppFileUrl = $templateMgr->smartyUrl($params, $templateMgr);
					$pattern = '/<a href="'. preg_quote($suppFileUrl, '/') .'" class="action">.+?<\/a>/';
					$replace = '<a href="'. $study->getPersistentUri() .'" class="action">'. __('plugins.generic.dataverse.suppFiles.view') .'</a>';
					$output = preg_replace($pattern, $replace, $output);
				}
			}
		}
		$templateMgr->unregister_outputfilter('rtSuppFilesOutputFilter');
		return $output;
	}

	/**
	 * Output filter replaces local suppfile download link in RT suppfile metadata
	 * page with link to dataset, if suppfile deposited in Dataverse.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return string Filtered output
	 */
	function rtSuppFileViewOutputFilter($output, &$templateMgr) {
		$article =& $templateMgr->get_template_vars('article');
		$suppFile =& $templateMgr->get_template_vars('suppFile');
		$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
		$dvFile = $dvFileDao->getDataverseFileBySuppFileId($suppFile->getId(), $article->getId());
		if (isset($dvFile)) {
			// Get citation for dataset that contains the file
			$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
			$study =& $dvStudyDao->getStudyBySubmissionId($article->getId());
			if (isset($study)) {
				// Replace local suppfile information with data citation & link to dataset
				$preMatch = '(<div id="supplementaryFileUpload">.+?<table width="100%" class="data">)';
				$postMatch = '(<\/table>\s*<\/div>)';
				$replace = 	'<tr valign="top">';
				$replace .= '<td width="20%" class="label">'. __('plugins.generic.dataverse.dataCitation') .'</td>';
				$replace .= '<td width="80%" class="value">'. $this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri()) .'</td>';
				$replace .= '</tr>';
				$output = preg_replace("/$preMatch.+?$postMatch/s", "$1$replace$2", $output);
			}
		}
		$templateMgr->unregister_outputfilter('rtSuppFileViewOutputFilter');
		return $output;
	}
	/**
	 * Output filter adds data citation to submission summary.
	 * @param $output string
	 * @param $templateMgr TemplateManager
	 * @return $string
	 */
	function submissionOutputFilter($output, &$templateMgr) {
		$submission =& $templateMgr->get_template_vars('submission');
		if (!isset($submission)) return $output;
			
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
		$study =& $dataverseStudyDao->getStudyBySubmissionId($submission->getId());

		$dataCitation = '';
		if (isset($study)) {
			$dataCitation = $this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri());
		}
		else {
			// There may be an external data citation
			$dataCitation = $submission->getLocalizedData('externalDataCitation');
		}
		if (!$dataCitation) return $output;

		$index = strpos($output, '<td class="label">'. __('submission.submitter'));
		if ($index !== false) {
			$newOutput = substr($output,0,$index);
			$newOutput .= '<td class="label">'.	 __('plugins.generic.dataverse.dataCitation') .'</td>';
			$newOutput .= '<td class="value" colspan="2">'. String::stripUnsafeHtml($dataCitation) .'</td></tr><tr>';
			$newOutput .= substr($output, $index);
			$output = $newOutput;
		}
		$templateMgr->unregister_outputfilter('submissionSummaryOutputFilter');
		return $output;
	}
	
	/**
	 * Hook callback: add data citation to article landing page.
	 * @see templates/article/article.tpl
	 */
	function addDataCitationArticle($hookName, $args) {
		$templateMgr =& $args[1];
		$output =& $args[2];

		$article =& $templateMgr->get_template_vars('article');
		
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
		$study =& $dataverseStudyDao->getStudyBySubmissionId($article->getId());
		if (isset($study)) {
			$templateMgr->assign('dataCitation', $this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri()));
		}
		else {
			// Article may have an external data citation
			$templateMgr->assign('dataCitation', $article->getLocalizedData('externalDataCitation'));
		}
		$output .= $templateMgr->fetch($this->getTemplatePath() . 'dataCitationArticle.tpl');
		return false;
	}	 
	
	/**
	 * Hook callback: register plugin settings fields with TinyMCE
	 * @see TinyMCEPlugin::getEnableFields()
	 */
	function getTinyMCEEnabledFields($hookName, $args) {
		$fields =& $args[1];

		$application =& Application::getApplication();
		$request =& $application->getRequest();
		$router =& $request->getRouter();

		// TinyMCEPlugin::getEnableFields hook is only invoked on page requests.
		$page = $router->getRequestedPage($request);
		$op = $router->getRequestedOp($request);
		$requestArgs = $router->getRequestedArgs($request);

		if ($page == 'manager' && $op == 'plugin' && in_array('dataverseplugin', $requestArgs)) {
			$fields = array('dataAvailability', 'termsOfUse');
		}
		return false;
	}

	/**
	 * Hook callback: add link to data availability policy to policies section of
	 * journal's About page
	 * @see templates/about/index.tpl
	 */
	function addPolicyLinks($hookName, $args) {
		$journal =& Request::getJournal();
		$dataPAvailability = $this->getSetting($journal->getId(), 'dataAvailability');
		if (!empty($dataPAvailability)) {
			$templateMgr =& $args[1];
			$output =& $args[2];
			$output .= '<li>&#187; <a href="'. $templateMgr->smartyUrl(array('page' => 'dataverse', 'op'=>'dataAvailabilityPolicy'), $templateMgr) .'">';
			$output .= __('plugins.generic.dataverse.settings.dataAvailabilityPolicy');
			$output .= '</a></li>';
		}
		return false;
	}
	
	/**
	 * Hook callback: add validators to fields added to metadata form
	 * @see Form::Form()
	 */
	function metadataFormConstructor($hookName, $args) {
		$form =& $args[0];
		$form->addCheck(new FormValidatorCustom($this, 'metadata', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.dataverse.metadataForm.studyLocked', array(&$this, 'formValidateStudyState'), array(&$form)));
		return false;
	}	 
	
	/**
	 * Hook callback: update cataloguing information on form submission, if article
	 * has a Dataverse study
	 * @see Form::execute()
	 */
	function metadataFormExecute($hookName, $args) {
		$form =& $args[0];
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
		$study =& $dataverseStudyDao->getStudyBySubmissionId($form->article->getId());
		if (isset($study)) {
			$metadataReplaced = $this->replaceStudyMetadata($form->article, $study);
			// Notify success/failure
			$user =& Request::getUser();
			import('classes.notification.NotificationManager');
			$notificationManager = new NotificationManager();
			$notificationManager->createTrivialNotification($user->getId(), $metadataReplaced ? NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED : NOTIFICATION_TYPE_ERROR);
		}
		return false;
	}	 
	
	/**
	 * Form validator added to metadata and suppfile forms: prevents form submission
	 * if article has files in Dataverse that are locked for processing
	 * @param $field string Field value
	 * @param $form Article metadata or suppfile form
	 * @return boolean true if study is NOT locked
	 */
	function formValidateStudyState($field, $form) {
		$articleId = isset($form->article) ? $form->article->getId() : $form->articleId;		
		$dvStudyDao = DAORegistry::getDAO('DataverseStudyDAO');
		$study = $dvStudyDao->getStudyBySubmissionId($articleId);		 

		// No study for this submission
		if (!isset($study)) return true;

		// Prevent submission if study is locked
		return $this->studyIsLocked($study) ? false : true;
	}
	
	/**
	 * Hook callback: notify ArticleDAO of external data citation field added to
	 * suppfile forms.
	 * @see ArticleDAO::getAdditionalFieldNames()
	 */
	function articleMetadataFormFieldNames($hookName, $args) {
		$fields =& $args[1];
		$fields[] = 'externalDataCitation';
		return false;		 
	}
	
	/**
	 * Hook callback: add data publication options to suppfile form templates for
	 * initial & completed submissions.
	 * @see templates/author/submit/suppFile.tpl
	 * @see templates/submission/suppFile/suppFile.tpl
	 */
	function suppFileAdditionalMetadata($hookName, $args) {
		$templateMgr =& $args[1];
		$output =& $args[2];
		$articleId = $templateMgr->get_template_vars('articleId');				 

		// Include Dataverse data citation, if a study exists for this submission.
		$dvStudyDao = DAORegistry::getDAO('DataverseStudyDAO');
		$study = $dvStudyDao->getStudyBySubmissionId($articleId);

		if (isset($study)) {
			$templateMgr->assign('dataCitation', $this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri()));
			$templateMgr->assign('studyLocked', $this->studyIsLocked($study));
		}
		$output .= $templateMgr->fetch($this->getTemplatePath() . 'suppFileAdditionalMetadata.tpl');
		return false;
	}
	
	/**
	 * Hook callback: add validators to fields added to suppfile form
	 * @see Form::Form
	 */
	function suppFileFormConstructor($hookName, $args) {
		$form =& $args[0];
		$form->addCheck(new FormValidatorCustom($this, 'publishData', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.dataverse.suppFile.publishData.error', array(&$this, 'suppFileFormValidateDeposit'), array(&$form)));
		$form->addCheck(new FormValidatorCustom($this, 'publishData', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.dataverse.suppFile.studyLocked', array(&$this, 'formValidateStudyState'), array(&$form)));
		$form->addCheck(new FormValidatorCustom($this, 'externalDataCitation', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.dataverse.suppFile.externalDataCitation.error', array(&$this, 'suppFileFormValidateCitations'), array(&$form))); 
		return false;
	}
	
	/**
	 * Suppfile form validator: return false if Dataverse deposit selected but no
	 * suppfile has been uploaded.
	 * @param $publishData Field value
	 * @param $form Suppfile form
	 * @return boolean true if suppfile has been uploaded
	 */
	function suppFileFormValidateDeposit($publishData, $form) {
		if ($publishData == 'dataverse') {
			// If suppfile exists & has a non-zero file ID, a file has been uploaded previously
			if ($form->suppFile && $form->suppFile->getFileId()) return true;
			
			// If no file has been uploaded, return false
			import('classes.file.ArticleFileManager');
			$articleId = isset($form->article) ? $form->article->getId() : $form->articleId;
			$articleFileManager = new ArticleFileManager($articleId);
			if (!$articleFileManager->uploadedFileExists('uploadSuppFile')) return false;
		}
		return true;
	}
	
	/**
	 * Suppfile form validator: return false if Dataverse deposit selected and an 
	 * external citation has been provided. Submitters must provide one or the
	 * other, not both.
	 * @param $externalCitation string field value
	 * @param $form Suppfile form
	 * @return boolean true if data submitted OR external citation provided
	 */
	function suppFileFormValidateCitations($externalCitation, $form) {
		if ($externalCitation && $form->getData('publishData') == 'dataverse') {
			return false;
		}
		return true;
	}
	
	/**
	 * Hook callback: initialize fields added to suppfile form
	 * @see Form::initData()
	 */
	function suppFileFormInitData($hookName, $args) {
		$form =& $args[0];

		$journal =& Request::getJournal();		
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article =& $form->article;		 
		if (!isset($article)) {
			$article = $articleDao->getArticle($form->articleId, $journal->getId());
		}
		// Add or edit external data citation field, if missed in previous step
		$form->setData('externalDataCitation', $article->getLocalizedData('externalDataCitation'));
		
		// Set data publishing option for this suppfile:
		// 'none'			 -- supplementary file, not published (default)
		// 'dataverse' -- deposit file in Dataverse and publish data citation with article
		$publishData = 'none';

		if (isset($form->suppFile) && $form->suppFile->getId()) {
			// Check if uploaded file has been deposited in Dataverse
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
			$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($form->suppFile->getId(), $article->getId());
			if (!is_null($dvFile)) { $publishData = 'dataverse'; }
		}
		$form->setData('publishData', $publishData);
		return false;
	}

	/**
	 * Hook callback: read values submitted in fields added to suppfile form
	 * @see Form::readUserVars()
	 */
	function suppFileFormReadUserVars($hookName, $args) {
		$form =& $args[0];
		$vars =& $args[1];
		$vars[] = 'externalDataCitation';
		$vars[] = 'publishData';
		return false;
	}

	/**
	 * Hook callback: handle suppfile form execution for incomplete submissions
	 * @see Form::execute()
	 */
	function authorSubmitSuppFileFormExecute($hookName, $args) {
		$form =& $args[0];

		// External data citation: field is article metadata, but provided in
		// suppfile form as well, at point of data file deposit, to help support
		// data publishing decisions.
		$journal =& Request::getJournal();		
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article = $articleDao->getArticle($form->articleId, $journal->getId());
		$article->setData('externalDataCitation', $form->getData('externalDataCitation'), $form->getFormLocale());
		$articleDao->updateArticle($article);
		
		if (!isset($form->suppFile) || !$form->suppFile->getId()) {
			// Suppfile metadata may exist but no file has been uploaded. 
			return false;
		}
		
		$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
		$dvFile = $dvFileDao->getDataverseFileBySuppFileId($form->suppFile->getId(), $form->articleId);			 

		switch ($form->getData('publishData')) {
			case 'none':
				// Treat uploaded file as supplementary. If previously marked for deposit, unmark it.
				if (isset($dvFile)) {
					$dvFileDao->deleteDataverseFile($dvFile);
				}
				break;

			case 'dataverse':
				// Mark file for deposit, if not marked already. File will be deposited
				// in Dataverse when submission is completed or accepted for publication.
				if (!isset($dvFile)) {
					$this->import('classes.DataverseFile');
					$dvFile = new DataverseFile();
					$dvFile->setSuppFileId($form->suppFile->getId());
					$dvFile->setSubmissionId($form->articleId);
					$dvFileDao->insertDataverseFile($dvFile);						 
				}
				break;
		}
		return false;
	}
	
	/**
	 * Hook callback: handle suppfile form execution for completed submissions
	 * @see Form::execute()
	 */
	function suppFileFormExecute($hookName, $args) {	 
		$form =& $args[0];
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article =& $form->article;
		
		// External data citation: field is article metadata, but provided in 
		// suppfile form as well, at point of data file deposit, to help support
		// data publishing decisions
		$article->setData('externalDataCitation', $form->getData('externalDataCitation'), $form->getFormLocale());
		$articleDao->updateArticle($article);
		
		// Form executed for completed submissions. Draft studies are created on 
		// submission completion. A study may or may not exist for this submission.
		switch ($form->getData('publishData')) {
			case 'none':
				// Supplementary file: do not deposit. 
				if ($form->suppFile->getId()) { 
					// Suppfile exists & may previously have been flagged for deposit / 
					// deposited in Dataverse
					$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');		
					$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($form->suppFile->getId(), $article->getId());
					if (isset($dvFile)) {
						// Remove file from pending deposit (if submission is incomplete) or 
						// dataset previously deposited 
						$fileDeleted = $this->deleteFile($dvFile);
						if ($fileDeleted) {
							$dvFileDao->deleteDataverseFile($dvFile);
						}
						// Deleting a file may affect study cataloguing information
						$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');				
						$study =& $dvStudyDao->getStudyBySubmissionId($article->getId());
						if (isset($study)) {
							$this->replaceStudyMetadata($article, $study);
						}
						// Notify
						$user =& Request::getUser();
						import('classes.notification.NotificationManager');
						$notificationManager = new NotificationManager();
						$notificationManager->createTrivialNotification($user->getId(), $fileDeleted ? NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED : NOTIFICATION_TYPE_ERROR);
					}
				}
				break;

			case 'dataverse':
				// Deposit file. Create a study, if submission doesn't have one already.
				$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
				$study =& $dvStudyDao->getStudyBySubmissionId($article->getId());	 
				if (!isset($study)) {
					$study = $this->createStudy($article);
				}
				if (!$form->suppFile->getId()) {
					// Suppfile is new, but inserted in db after hook is called. Handle
					// insertion here & prevent duplicates in handleSuppFileInsertion() callback
					$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
					$form->setSuppFileData($form->suppFile);
					$suppFileDao->insertSuppFile($form->suppFile);
					$form->suppFileId = $form->suppFile->getId();
					$form->suppFile =& $suppFileDao->getSuppFile($form->suppFileId, $article->getId());
					// Add suppfile to study. 
					$deposited = $this->depositFiles($study, array($form->suppFile));
					// File-level metadata may need to be added to study.
					$this->replaceStudyMetadata($article, $study);
					// & notify:
					$user =& Request::getUser();
					import('classes.notification.NotificationManager');
					$notificationManager = new NotificationManager();
					$notificationManager->createTrivialNotification($user->getId(), $deposited ? NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED: NOTIFICATION_TYPE_ERROR);
				}
				else {
					// Suppfile exists, and uploaded file may be new or a replacement
					// (form validator requires file upload if Dataverse option selected).
					// Hook is called before suppfile object is updated with details of 
					// uploaded file, so refresh suppfile object here. 
					import('classes.file.ArticleFileManager');
					$fileName = 'uploadSuppFile';
					$articleFileManager = new ArticleFileManager($article->getId());
					if ($articleFileManager->uploadedFileExists($fileName)) {
						$fileId = $form->suppFile->getFileId();
						if ($fileId != 0) {
							$articleFileManager->uploadSuppFile($fileName, $fileId);
						}
						else {
							$fileId = $articleFileManager->uploadSuppFile($fileName);	 
							$form->suppFile->setFileId($fileId);					
						}
					}
					// Store form metadata. It may be used to update study cataloguing information.
					$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
					$form->suppFile =& $suppFileDao->getSuppFile($form->suppFileId, $article->getId());
					$form->setSuppFileData($form->suppFile);
					$suppFileDao->updateSuppFile($form->suppFile);
					
					// The uploaded file may be new, or it may replace a previously-deposited
					// file. If it's a replacement, delete the existing file from Dataverse:
					// this prevents orphaned files on the Dataverse side, and filename collisions
					// described in https://redmine.hmdc.harvard.edu/issues/3301
					$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');							
					$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($form->suppFileId, $article->getId());
					if (isset($dvFile)) {
						$this->deleteFile($dvFile);
						$dvFileDao->deleteDataverseFile($dvFile);
 					}
					// Add uploaded suppfile to study.
					$deposited = $this->depositFiles($study, array($form->suppFile));
					// File-level metadata may need to be added to study.
 					$this->replaceStudyMetadata($article, $study);
					// & notify:
					$user =& Request::getUser();
					import('classes.notification.NotificationManager');
					$notificationManager = new NotificationManager();
 					$notificationManager->createTrivialNotification($user->getId(), $deposited ? NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED: NOTIFICATION_TYPE_ERROR);
 				}
				break;
				
			default:
				// Unknown option
				assert(false);
		}
		return false;
	}

	/**
	 * Hook callback: prevent re-insertion of suppfile already handled in suppfile
	 * form execution callback.
	 * @see DataversePlugin::suppFileFormExecute()
	 * @see DAO::update()
	 */
	function handleSuppFileInsertion($hookName, $args) {
		$params =& $args[1];
		$fileId = $params[1];		 
		$articleId = $params[2];
		
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		return $suppFileDao->suppFileExistsByFileId($articleId, $fileId);
	}
	
	/**
	 * Hook callback: when suppfile deleted, remove data file from Dataverse
	 * study, if present
	 * @see DAO::update()
	 */
	function handleSuppFileDeletion($hookName, $args) {
		$params =& $args[1];
		$suppFileId = is_array($params) ? $params[0] : $params;
		$submissionId = is_array($params) ? $params[1] : '';
		
		// Suppfile deposited in / marked for deposit in Dataverse?
		$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
		$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($suppFileId, $submissionId ? $submissionId : '');
		if (isset($dvFile)) {
			$this->deleteFile($dvFile);

			// If file belongs to a completed submission & study present in Dataverse,
			// replace cataloguing information to remove metadata assoc. w/ deleted file
			$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
			$study =& $dvStudyDao->getStudyBySubmissionId($dvFile->getSubmissionId());
			if (isset($study)) {
				$articleDao =& DAORegistry::getDAO('ArticleDAO');
				$journal =& Request::getJournal();
				$article =& $articleDao->getArticle($study->getSubmissionId(), $journal->getId());
				$this->replaceStudyMetadata($article, $study);
			}
		}
		return false;
	}
	
	/**
	 * Hook callback: add form validator to verify submissions include data files
	 * @see Form::Form()
	 */
	function addAuthorSubmitFormValidator($hookName, $args) {
		$form =& $args[0];
		$form->addCheck(new FormValidatorCustom($form, '', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.dataverse.settings.requireDataError', array(&$this, 'validateRequiredData'), array($form)));
	}
	
	/**
	 * Form validator: verify data files have been provided, if required in plugin
	 * settings.
	 * @param $fieldValue string field value
	 * @param $form Author submission form, step 4
	 * @return boolean
	 */
	function validateRequiredData($fieldValue, $form) {
		$journal =& Request::getJournal();
		if (!$this->getSetting($journal->getId(), 'requireData')) return true;
		// Data files must be provided.
		$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
		$dvFiles =& $dvFileDao->getDataverseFilesBySubmissionId($form->articleId);
		return count($dvFiles);
	}
	
	/**
	 * Hook callback: create draft study if author has uploaded data files
	 * @see SubmitHandler::saveSubmit()
	 */
	function handleAuthorSubmission($hookName, $args) {
		$step =& $args[0];
		$article =& $args[1];
		if ($step == 5) {
			// Author has completed submission. If suppfiles selected for deposit in 
			// Dataverse, create a new study.
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
			$dvFiles =& $dvFileDao->getDataverseFilesBySubmissionId($article->getId());
			if ($dvFiles) {	
				$study = $this->createStudy($article);
				if ($study) {
					// DataverseFiles act as placeholders until suppfiles deposited. 
					// Deposit suppfiles, then update DataverseFiles with new study / dataset ID
					$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');						
					$suppFiles = array();					
					foreach ($dvFiles as $dvFile) {
						$suppFiles[] = $suppFileDao->getSuppFile($dvFile->getSuppFileId(), $article->getId());
					}
					$this->depositFiles($study, $suppFiles);
				}
				// notify:
				import('classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();				
				$user =& Request::getUser();
				$notificationManager->createTrivialNotification($user->getId(), isset($study) ? NOTIFICATION_TYPE_DATAVERSE_STUDY_CREATED : NOTIFICATION_TYPE_ERROR);
			}
		}
		return false;
	}
	
	/**
	 * Hook callback: release or delete study based on editor decision 
	 * @see SectionEditorAction::recordDecision()
	 */
	function handleEditorDecision($hookName, $args) {
		$submission =& $args[0];
		$decision =& $args[1];
		 
		// Plugin may be configured to release on publication: defer decision 
		if ($this->getSetting($submission->getJournalId(), 'studyRelease') == DATAVERSE_PLUGIN_RELEASE_ARTICLE_PUBLISHED) {
			return false;
		}

		// Find study associated with submission
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
		$study =& $dataverseStudyDao->getStudyBySubmissionId($submission->getId());
		
		if (isset($study)) {
			// Editor decision on a submission with a draft study in Dataverse
			if ($decision['decision'] == SUBMISSION_EDITOR_DECISION_ACCEPT) {
				$this->releaseStudy($study);
			}
			if ($decision['decision'] == SUBMISSION_EDITOR_DECISION_DECLINE) {
				// Draft studies will be deleted; released studies will be deaccesioned
				$this->deleteStudy($study);
			}
		}
		return false;
	}
	
	/**
	 * Hook callback: release study on article publication
	 * @see DAO::update()
	 */
	function handleArticleUpdate($hookName, $args) {
		$journal =& Request::getJournal();
		$params =& $args[1];
		$articleId = $params[count($params)-1];
		$status = $params[6];
		
		if ($this->getSetting($journal->getId(), 'studyRelease') == DATAVERSE_PLUGIN_RELEASE_ARTICLE_PUBLISHED) {
			// See if study exists for submission
			$dvStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
			$study =& $dvStudyDao->getStudyBySubmissionId($articleId);
			if (isset($study) && $status == STATUS_PUBLISHED) { 
				$this->releaseStudy($study);
			}
		}
		return false;
	}
	
	/**
	 * Hook callback: delete study if editor rejects submission
	 * @see SectionEditorAction::unsuitableSubmission()
	 */
	function handleUnsuitableSubmission($hookName, $args) {
		$submission =& $args[0];		
		$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
		$study =& $dataverseStudyDao->getStudyBySubmissionId($submission->getId());
		if (isset($study)) {
			$this->deleteStudy($study);
		}
		return false;		 
	}
	
	/**
	 * Request Dataverse Network service document
	 * 
	 * @param $sdUrl string service document URL
	 * @param $user string username 
	 * @param $password string password
	 * @param $onBehalfOf string send request on behalf of user
	 * @return SWORDAPPServiceDocument
	 */
	function getServiceDocument($sdUrl, $user, $password, $onBehalfOf = NULL) {
		// allow insecure SSL connections
		$client = $this->_initSwordClient();
		return $client->servicedocument($sdUrl, $user, $password, $onBehalfOf);
	} 
	
	/**
	 * Get terms of use of Dataverse configured for the journal
	 * @return string 
	 */
	function getTermsOfUse() {
		$journal =& Request::getJournal();
		$sd = $this->getServiceDocument(
						$this->getSetting($journal->getId(), 'sdUri'), 
						$this->getSetting($journal->getId(), 'username'), 
						$this->getSetting($journal->getId(), 'password')
					);
		
		$dvTermsOfUse = '';
		if ($sd->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_OK) {
			$dvUri = $this->getSetting($journal->getId(), 'dvUri');
				
			// Find workspaces defined in service document
			foreach ($sd->sac_workspaces as $workspace) {
				foreach ($workspace->sac_collections as $collection) {
					if ($collection->sac_href[0] == $dvUri) {
						// TOU constructed from policies at dataverse, collection, study
						//	levels and separated by hyphens. Kludge in some line breaks.
						$dvTermsOfUse = str_replace(
										DATAVERSE_PLUGIN_TOU_POLICY_SEPARATOR, 
										'<br/>'. DATAVERSE_PLUGIN_TOU_POLICY_SEPARATOR .'<br/>', 
										$collection->sac_collpolicy
										);
						// Store DV terms of use as a fallback 
						$this->updateSetting($journal->getId(), 'dvTermsOfUse', $dvTermsOfUse, 'string');
						break;
					}
				}
			}
		}
		return $dvTermsOfUse;
	}

	/**
	 * Create deposit package of article metadata.
	 * @param $article Article
	 * @return DataversePackager
	 */
	function createMetadataPackage($article) {
		$package = new DataversePackager();
		// Article metadata
		$package->addMetadata('title', $article->getLocalizedTitle());
		$package->addMetadata('description', $article->getLocalizedAbstract());
		foreach ($article->getAuthors() as $author) {
			$package->addMetadata('creator', $author->getFullName(true));
		}
		// subject: academic disciplines
		$split = '/\s*'. DATAVERSE_PLUGIN_SUBJECT_SEPARATOR .'\s*/';
		foreach(preg_split($split, $article->getLocalizedDiscipline(), NULL, PREG_SPLIT_NO_EMPTY) as $subject) {
			$package->addMetadata('subject', $subject);
		}
		// subject: subject classifications
		foreach(preg_split($split, $article->getLocalizedSubjectClass(), NULL, PREG_SPLIT_NO_EMPTY) as $subject) {
			$package->addMetadata('subject', $subject);
		}
		// subject:	 keywords		 
		foreach(preg_split($split, $article->getLocalizedSubject(), NULL, PREG_SPLIT_NO_EMPTY) as $subject) {
			$package->addMetadata('subject', $subject);
		}
		// geographic coverage
		foreach(preg_split($split, $article->getLocalizedCoverageGeo(), NULL, PREG_SPLIT_NO_EMPTY) as $coverage) {
			$package->addMetadata('coverage', $coverage);
		}
		// Fetch journal for published article and journal metadata
		$journalDao =& DAORegistry::getDAO('JournalDAO');
		$journal =& $journalDao->getById($article->getJournalId());
		// Published articles
		$pubIdAttributes = array();
		if ($article->getStatus() == STATUS_PUBLISHED) {
			// publication date
			$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId($article->getId(), $article->getJournalId());
			$datePublished = $publishedArticle->getDatePublished();
			if (!$datePublished) {
				// If article has no pub date, use issue pub date
				$issueDao =& DAORegistry::getDAO('IssueDAO');
				$issue =& $issueDao->getIssueByArticleId($article->getId(), $article->getJournalId());
				$datePublished = $issue->getDatePublished();        
			}
			$package->addMetadata('date', strftime('%Y-%m-%d', strtotime($datePublished)));
			// isReferencedBy: add persistent URL to citation using specified pubid plugin
			$pubIdPlugin =& PluginRegistry::getPlugin('pubIds', $this->getSetting($article->getJournalId(), 'pubIdPlugin'));
			if ($pubIdPlugin && $pubIdPlugin->getEnabled()) {
				$pubIdAttributes['agency'] = $pubIdPlugin->getDisplayName();
				$pubIdAttributes['IDNo'] = $article->getPubId($pubIdPlugin->getPubIdType());
				$pubIdAttributes['holdingsURI'] = $pubIdPlugin->getResolvingUrl($article->getJournalId(), $pubIdAttributes['IDNo']);
			}
			// If no pubIdP plugin selected or enabled, provide OJS URL
			if(!array_key_exists('holdingsURI', $pubIdAttributes)) {
				$pubIdAttributes['holdingsURI'] = Request::url($journal->getPath(), 'article', 'view', array($article->getId()));
			}
		}
		// Journal metadata
		$package->addMetadata('publisher', $journal->getSetting('publisherInstitution'));
		$package->addMetadata('rights', $journal->getLocalizedSetting('copyrightNotice'));
		$package->addMetadata('isReferencedBy', $this->getCitation($article), $pubIdAttributes);
		
		// Suppfile metadata
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');				
		$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
		$dvFiles =& $dvFileDao->getDataverseFilesBySubmissionId($article->getId());
		foreach ($dvFiles as $dvFile) {
			$suppFile =& $suppFileDao->getSuppFile($dvFile->getSuppFileId(), $article->getId());
			if ($suppFile) {
				foreach(preg_split($split, $suppFile->getSuppFileSubject(), NULL, PREG_SPLIT_NO_EMPTY) as $subject) {
					$package->addMetadata('subject', $subject);
				}
				if ($suppFile->getType()) $package->addMetadata('type', $suppFile->getType());
				if ($suppFile->getSuppFileTypeOther()) $package->addMetadata('type', $suppFile->getSuppFileTypeOther());
			}
		}
		// Write metadata as Atom entry
		$package->createAtomEntry();
		
		// Return package for deposit
		return $package;
	}
	
	/**
	 * Create a Dataverse study: create deposit package with journal-, article-, 
	 * and suppfile-level metadata, then deposit in Dataverse to create study.
	 * @param $article
	 * @return DataverseStudy
	 */
	function createStudy($article) {
		$package = $this->createMetadataPackage($article);
		$client = $this->_initSwordClient();
		$depositReceipt = $client->depositAtomEntry(
						$this->getSetting($article->getJournalId(), 'dvUri'), 
						$this->getSetting($article->getJournalId(), 'username'), 
						$this->getSetting($article->getJournalId(), 'password'),
						'',	 // on behalf of: no one
						$package->getAtomEntryFilePath());
		
		$study = null;
		if ($depositReceipt->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_CREATED) {
			$this->import('classes.DataverseStudy');
			$study = new DataverseStudy();
			$study->setSubmissionId($article->getId());
			$study->setEditUri($depositReceipt->sac_edit_iri);
			$study->setEditMediaUri($depositReceipt->sac_edit_media_iri);
			$study->setStatementUri($depositReceipt->sac_state_iri_atom);
			$study->setDataCitation($depositReceipt->sac_dcterms['bibliographicCitation'][0]);
				
			// Persistent URI may be present, as an altenate 
			foreach ($depositReceipt->sac_links as $link) {
				if ($link->sac_linkrel == 'alternate') {
					$study->setPersistentUri($link->sac_linkhref);
					break;
				}
			}
			// Insert new Dataverse study for this submission
			$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');			 
			$dataverseStudyDao->insertStudy($study);
		}
		return $study;
	}
	
	/**
	 * Update cataloguing information for an existing study. Metadata in the Atom
	 * entry replaces all cataloguing information currently defined for the
	 * study.
	 * @param Article $article
	 * @param DataverseStudy $study
	 * @return boolean Cataloguing information updated
	 */
	function replaceStudyMetadata($article, $study) {
		$package = $this->createMetadataPackage($article);
		$client = $this->_initSwordClient();
		$depositReceipt = $client->replaceMetadata(
						$study->getEditUri(),
						$this->getSetting($article->getJournalId(), 'username'), 
						$this->getSetting($article->getJournalId(), 'password'),						
						'', // on behalf of
						$package->getAtomEntryFilePath());

		$metadataReplaced = ($depositReceipt->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_OK);
		if ($metadataReplaced) {
			// Data citation may have changed on updating cataloguing information
			$study->setDataCitation($depositReceipt->sac_dcterms['bibliographicCitation'][0]);
			$dataverseStudyDao =& DAORegistry::getDAO('DataverseStudyDAO');
			$dataverseStudyDao->updateStudy($study);
		}
		return $metadataReplaced;
	}
	
	/**
	 * Deposit suppfiles in Dataverse study.
	 * @param DataverseStudy $study Study associated with author submission
	 * @param Array $suppFiles Array of suppfiles
	 * @return boolean Files deposited
	 */
	function depositFiles($study, $suppFiles) {
		$packager = new DataversePackager();
		foreach ($suppFiles as $suppFile) {
			$suppFile->setFileStage(ARTICLE_FILE_SUPP); // workaround for #8444			
			$packager->addFile($suppFile->getFilePath(), $suppFile->getOriginalFileName());
		}
		$packager->createPackage();

		// Deposit the package
		$journal =& Request::getJournal();
		$client = $this->_initSwordClient();		 
		$depositReceipt = $client->deposit(
						$study->getEditMediaUri(),
						$this->getSetting($journal->getId(), 'username'),
						$this->getSetting($journal->getId(), 'password'),
						'', // on behalf of: no one
						$packager->getPackageFilePath(),
						$packager->getPackaging(),
						$packager->getContentType(),
						false); // in progress? false 
		
		$deposited = ($depositReceipt->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_CREATED);
		if ($deposited) {
			// Get the study statement & update the local file list
			$studyStatement = $client->retrieveAtomStatement(
						$study->getStatementUri(),
						$this->getSetting($journal->getId(), 'username'),
						$this->getSetting($journal->getId(), 'password'),
						'' // on behalf of
			);
			if ($studyStatement) {
				// Associate Dataverse-side file with OJS-side file
				$dvFileIndex = array();
				foreach ($studyStatement->sac_entries as $entry) {
					if (strrpos($entry->sac_content_source, '/')) {
						$dvUriFileName = substr($entry->sac_content_source, strrpos($entry->sac_content_source, '/')+1);
						$dvFileIndex[$dvUriFileName] = $entry->sac_content_source;
					}
				}
				// Create or update DataverseFile linking suppfile with deposited file
				$this->import('classes.DataverseFile');			
				$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
				foreach ($suppFiles as $suppFile) {
					$suppFileKey = str_replace(' ', '_', $suppFile->getOriginalFileName());
					if (array_key_exists($suppFileKey, $dvFileIndex)) {
						$dvFile =& $dvFileDao->getDataverseFileBySuppFileId($suppFile->getId());
						if (!$dvFile) {
							$dvFile = new DataverseFile();
							$dvFile->setSuppFileId($suppFile->getId());
							$dvFile->setSubmissionId($study->getSubmissionId());						
							$dvFile->setStudyId($study->getId());
							$dvFile->setContentSourceUri($dvFileIndex[$suppFileKey]);
							$dvFileDao->insertDataverseFile($dvFile);												
						}
						else {
							$dvFile->setStudyId($study->getId());
							$dvFile->setContentSourceUri($dvFileIndex[$suppFileKey]);						
							$dvFileDao->updateDataverseFile($dvFile);
						}
					}
				}
			}
		}
		return $deposited;
	}	

	/**
	 * Indicate whether Dataverse has been released. 
	 * @return boolean 
	 */
	function dataverseIsReleased() {
		$journal =& Request::getJournal();
		$client = $this->_initSwordClient();		

		$depositReciept = $client->retrieveDepositReceipt(
				$this->getSetting($journal->getId(), 'dvUri'), 
				$this->getSetting($journal->getId(), 'username'),
				$this->getSetting($journal->getId(), 'password'), 
				''); // on behalf of

		if ($depositReciept->sac_status != DATAVERSE_PLUGIN_HTTP_STATUS_OK) return false;
					
		$depositReceiptXml = @new SimpleXMLElement($depositReciept->sac_xml);
		$releasedNodes = $depositReceiptXml->children('http://purl.org/net/sword/terms/state')->dataverseHasBeenReleased;

		if (!empty($releasedNodes)) {
			$released = $releasedNodes[0];
		}
		return ($released == 'true');
	}
	
	/**
	 * Release draft study.
	 * @param DataverseStudy $study
	 * @return boolean Study released
	 */
	function releaseStudy(&$study) {
		$journal =& Request::getJournal();
		$user =& Request::getUser();
		
		$client = $this->_initSwordClient();		
		$response = $client->completeIncompleteDeposit(
						$study->getEditUri(),
						$this->getSetting($journal->getId(), 'username'),
						$this->getSetting($journal->getId(), 'password'),		
						''); // on behalf of
		
		$studyReleased = ($response->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_OK); 

		// Notify on success or failure. Provide citation & link to study.
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();

		if ($studyReleased) {
			$params = array('dataCitation' => $this->_formatDataCitation($study->getDataCitation(), $study->getPersistentUri()));
			$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_DATAVERSE_STUDY_RELEASED, $params);
		}
		else {
			$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_ERROR);
		}
		
		// Whether the study was released or not, notify JMs by email if Dataverse
		// has not yet been released. Released studies are not accessible until
		// the Dataverse has been released.
		if (!$this->dataverseIsReleased()) {
			$request =& Application::getRequest();										
			$roleDao =& DAORegistry::getDAO('RoleDAO');
			$journalManagers =& $roleDao->getUsersByRoleId(ROLE_ID_JOURNAL_MANAGER, $journal->getId());
			while ($journalManagers && !$journalManagers->eof()) {
				$journalManager =& $journalManagers->next();
				$notification = $notificationManager->createNotification($request, $journalManager->getId(), NOTIFICATION_TYPE_DATAVERSE_UNRELEASED, $journal->getId(), ASSOC_TYPE_JOURNAL, $journal->getId(), NOTIFICATION_LEVEL_NORMAL);
				$notificationManager->sendNotificationEmail($request, $notification);
				unset($journalManager);
			} // end notifying JMs			
		} // end if (study not released)
		
		return $studyReleased;
	}
	
	/**
	 * Delete draft study or deaccession released study.
	 * @param DataverseStudy $study
	 * @return boolean Study deleted
	 */
	function deleteStudy(&$study) {
		$journal =& Request::getJournal();
		$client = $this->_initSwordClient();
		$response = $client->deleteContainer(
								$study->getEditUri(), 
								$this->getSetting($journal->getId(), 'username'),
								$this->getSetting($journal->getId(), 'password'),
								''); // on behalf of 
		
		$studyDeleted = ($response->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_NO_CONTENT);
		
		// Notify on success or failure
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		$user =& Request::getUser();
		
		if ($studyDeleted) {
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');
			$dvFileDao->deleteDataverseFilesByStudyId($study->getId());
			$dataverseStudyDao = DAORegistry::getDAO('DataverseStudyDAO');
			$dataverseStudyDao->deleteStudy($study);
			$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_DATAVERSE_STUDY_DELETED);
		}
		else {
			$notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_ERROR);			 
		}
		return $studyDeleted;
	}
	
	/**
	 * Delete a file from a study
	 * @param $dvFile DataverseFile
	 * @return boolean File deleted
	 */
	function deleteFile(&$dvFile) {
		$journal =& Request::getJournal();
		if (!$dvFile->getContentSourceUri()) {
			// File hasn't been deposited in Dataverse yet
			$dvFileDao =& DAORegistry::getDAO('DataverseFileDAO');			
			return $dvFileDao->deleteDataverseFile($dvFile);
		}
		
		// File is in Dataverse. Remove from study & db.
		$client = $this->_initSwordClient();
		$response = $client->deleteResourceContent(
						$dvFile->getContentSourceUri(),
						$this->getSetting($journal->getId(), 'username'),
						$this->getSetting($journal->getId(), 'password'),
						'' // on behalf of
						);
		
		$fileDeleted = ($response->sac_status == DATAVERSE_PLUGIN_HTTP_STATUS_NO_CONTENT);
		return $fileDeleted;
	}
	
	/**
	 * Hook callback: add content to custom notifications
	 * @see NotificationManager::getNotificationContents()
	 */
	function getNotificationContents($hookName, $args) {
		$notification =& $args[0];
		$message =& $args[1];
		
		$type = $notification->getType();
		assert(isset($type));

		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();

		switch ($type) {
			case NOTIFICATION_TYPE_ERROR:
				$message = __('plugins.generic.dataverse.notification.error');
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_FILE_ADDED:
				$message = __('plugins.generic.dataverse.notification.fileAdded');
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_FILE_DELETED:
				$message = __('plugins.generic.dataverse.notification.fileDeleted');
				break;

			case NOTIFICATION_TYPE_DATAVERSE_STUDY_CREATED:
				$message = __('plugins.generic.dataverse.notification.studyCreated');
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_STUDY_UPDATED:
				$message = __('plugins.generic.dataverse.notification.studyUpdated');
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_STUDY_DELETED:
				$message = __('plugins.generic.dataverse.notification.studyDeleted');
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_STUDY_RELEASED:
				$notificationSettingsDao =& DAORegistry::getDAO('NotificationSettingsDAO');
				$params = $notificationSettingsDao->getNotificationSettings($notification->getId());
				$message = __('plugins.generic.dataverse.notification.studyReleased', $notificationManager->getParamsForCurrentLocale($params));
				break;
			
			case NOTIFICATION_TYPE_DATAVERSE_UNRELEASED:
				$notificationSettingsDao =& DAORegistry::getDAO('NotificationSettingsDAO');
				$params = $notificationSettingsDao->getNotificationSettings($notification->getId());
				$message = __('plugins.generic.dataverse.notification.releaseDataverse', $notificationManager->getParamsForCurrentLocale($params));
				break;
		}
	}	 
	
	/**
	 * Wrapper function initializes SWORDv2 client with cURL option to allow
	 * connections to servers with self-signed certificates.
	 * @param $options array
	 * @return SWORDAPPClient
	 */
	function _initSwordClient($options = array(CURLOPT_SSL_VERIFYPEER => FALSE)) {
		return new SWORDAPPClient($options);
	}
	
	/**
	 * Indicates whether study is locked for processing
	 * @param $study DataverseStudy
	 * @return boolean
	 */
	function studyIsLocked($study) {
		$journal =& Request::getJournal();
		$client = $this->_initSwordClient();		
		$locked = false;		
		$statement = $client->retrieveAtomStatement($study->getStatementUri(), $this->getSetting($journal->getId(), 'username'), $this->getSetting($journal->getId(), 'password'), '');
		try {
			$statementXml = new SimpleXMLElement($statement->sac_xml); 
			foreach ($statementXml->category as $category) {
				if ($category->attributes()->{'term'} == 'locked') {
					$locked = $category->attributes()->{'term'} == 'true' ? true : false;
					break;
				}
			}				 
		}
		catch (Exception $e) {
			$application =& PKPApplication::getApplication();
			error_log($application->getName() .'\n '. $e->getMessage() .'\n In file: '. $e->getFile() . '\n At line: '. $e->getLine());
		}			 
		return $locked;
	}

	/**
	 * Returns article citation to include in study cataloguing metadata.
	 * @param type $article
	 * @return string
	 */
	function getCitation($article) {
		$citationFormat = $this->getSetting($article->getJournalId(), 'citationFormat');
		$journal =& Request::getJournal();
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$issue =& $issueDao->getIssueByArticleId($article->getId(), $article->getJournalId());

		$templateMgr =& TemplateManager::getManager();
		$templateMgr->assign_by_ref('article', $article);
		if ($article->getStatus() == STATUS_PUBLISHED) {
			$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId($article->getId(), $article->getJournalId(), TRUE);
			$templateMgr->assign_by_ref('publishedArticle', $publishedArticle);
		}
		$templateMgr->assign_by_ref('issue', $issue);
		$templateMgr->assign_by_ref('journal', $journal); 
		
		return $templateMgr->fetch($this->getTemplatePath() .'citation'. $citationFormat .'.tpl');
	}
  
	/**
	 * Deposit receipt sent back by Data Deposit API contains a plain-text data
	 * citation and a persistent URI. Replace URI in citation with markup to link
	 * to cited study.
	 * @param $dataCitation string Plain-text data citation
	 * @param $persistentUri string Persistent URI for study
	 * @return string HTML formatted citation
	 */
	function _formatDataCitation($dataCitation, $persistentUri) {
	 return str_replace($persistentUri, '<a href="'. $persistentUri .'">'. $persistentUri .'</a>', strip_tags($dataCitation));
	}
}

?>
