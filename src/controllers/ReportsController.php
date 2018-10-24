<?php

namespace Masuga\LinkVault\controllers;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Masuga\LinkVault\LinkVault;
use Masuga\LinkVault\elements\LinkVaultReport;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReportsController extends Controller
{

	/**
	 * The instance of the Link Vault plugin object.
	 * @var LinkVault
	 */
	private $plugin = null;

	public function init()
	{
		parent::init();
		$this->plugin = LinkVault::getInstance();
	}

	/**
	 * This controller action presents the user with the reporting tool landing page
	 * and/or results based on the entered criteria.
	 * @return Response
	 */
	public function actionIndex(): Response
	{
		$request = Craft::$app->getRequest();
		$options = $this->plugin->general->reportAttributeOptions();
		$criteria = $request->getParam('criteria');
		$orderBy = $request->getParam('orderBy');
		$sort = $request->getParam('sort');
		$reportId = $request->getParam('reportId');
		return $this->renderTemplate('linkvault/_reports', [
			'criteria' => $criteria,
			'criteriaAttributes' => $options,
			'orderBy' => $orderBy ?: 'dateCreated',
			'sort' => $sort ?: 'desc',
			'report' => $reportId ? $this->plugin->reports->fetchReportById($reportId) : null
		]);
	}

	/**
	 * This controller action generates a CSV report based on supplied criteria
	 * from the reports form.
	 * @return Response
	 */
	public function actionExportCsv(): Response
	{
		$request = Craft::$app->getRequest();
		$criteria = $request->getParam('criteria');
		$orderBy = $request->getParam('orderBy');
		$sort = $request->getParam('sort');
		// This is simply uses as a pagination limit so we don't load all report records at once.
		$limit = 10;
		$offset = $count = 0;
		if ( in_array($orderBy, ['dateCreated', 'dateUpdated']) ) {
			$orderBy = 'linkvault_downloads.'.$orderBy;
		}
		// Determine an appropriate filename and file path for the generated file.
		$reportName = $this->plugin->export->generateReportFileName($criteria).'.csv';
		$reportPath = Craft::$app->getPath()->getRuntimePath().'/'.$reportName;
		// Query the records in batches to prevent the request from using too much memory.
		do {
			$offset += $count;
			$records = $this->plugin->general->records($criteria)->orderBy($orderBy.' '.$sort)->limit($limit)->offset($offset)->all();
			$count = count($records);
			$recordsArray = ArrayHelper::toArray($records);
			// Clean up odd criteria columns out of the record array, by reference.
			foreach($recordsArray as &$record) {
				// This method is also a "by reference" call, hence no return value.
				$this->plugin->general->cleanRecordArray($record);
			}
			// Set a boolean to determine whether or not we should include the column header.
			$includeColumnHeader = ( $offset === 0 ) ? true : false;
			$csvContent = $this->plugin->export->convertArrayToDelimitedContent($recordsArray, ',', $includeColumnHeader);
			$this->plugin->export->writeToFile($reportPath, $csvContent);
		} while ($count > 0);
		$response = Craft::$app->getResponse();
		return $response->sendFile($reportPath);
	}

	/**
	 * This controller action method either creates or updates an existing report
	 * element.
	 * @return Response
	 */
	public function actionSaveReport(): Response
	{
		$this->requirePostRequest();
		$request = Craft::$app->getRequest();
		$id = $request->post('reportId');
		$fields = [
			'title' => $request->post('title'),
			'criteria' => json_encode($request->post('criteria')),
			'orderBy' => $request->post('orderBy'),
			'sort' => $request->post('sort')
		];
		$report = $this->plugin->reports->saveReport($fields, $id);
		if ( $report ) {
			Craft::$app->getSession()->setNotice(Craft::t('linkvault', 'Link Vault report criteria saved!'));
			$response = $this->asJson(['url' => $report->getUrl()]);
		} else {
			Craft::$app->getSession()->setError(Craft::t('linkvault', 'Error saving the Link Vault report criteria.'));
			$response = $this->asJsoin(['error' => Craft::t('linkvault', 'Unable to save report')]);
		}
		return $response;
	}

	/**
	 * This controller action method deletes a Link Vault report element by ID.
	 * @return Response
	 * @throws NotFoundHttpException
	 */
	public function actionDelete(): Response
	{
		$request = Craft::$app->getRequest();
		$id = $request->getParam('reportId');
		$report = $this->plugin->reports->fetchReportById($id);
		if ( ! $report ) {
			throw new NotFoundHttpException('Report not found.');
		}
		Craft::$app->db->createCommand()->delete('{{%linkvault_reports}}', ['id' => $report->id]);
		$deleted = Craft::$app->getElements()->deleteElementById($report->id);
		if ( $deleted ) {
			Craft::$app->getSession()->setNotice(Craft::t('linkvault', 'Link Vault report criteria deleted!'));
		} else {
			Craft::$app->getSession()->setError(Craft::t('linkvault', 'Error deleting the Link Vault report criteria.'));
		}
		$response = $this->redirect(UrlHelper::cpUrl('linkvault/reports'));
		return $response;
	}

}
