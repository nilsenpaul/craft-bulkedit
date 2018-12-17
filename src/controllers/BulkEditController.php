<?php
/**
 * Bulk Edit plugin for Craft CMS 3.x
 *
 * Bulk edit entries
 *
 * @link      https://venveo.com
 * @copyright Copyright (c) 2018 Venveo
 */

namespace venveo\bulkedit\controllers;

use Craft;
use craft\models\FieldLayout;
use craft\records\Element;
use craft\records\Field;
use craft\web\Controller;
use craft\web\Response;
use venveo\bulkedit\assetbundles\bulkeditscreen\BulkEditScreenAsset;
use venveo\bulkedit\BulkEdit;
use venveo\bulkedit\BulkEdit as Plugin;
use venveo\bulkedit\queue\jobs\SaveBulkEditJob;
use venveo\bulkedit\records\EditContext;
use venveo\bulkedit\records\History;
use venveo\bulkedit\services\BulkEdit as BulkEditService;
use yii\web\BadRequestHttpException;

/**
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Venveo
 * @package   BulkEdit
 * @since     1.0.0
 */
class BulkEditController extends Controller
{
    /**
     * Return the file preview for an Asset.
     *
     * @return Response
     * @throws BadRequestHttpException if not a valid request
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     */
    public function actionGetFields(): Response
    {
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $site = Craft::$app->getSites()->getCurrentSite();
        $elementIds = Craft::$app->getRequest()->getRequiredParam('elementIds');
        $requestId = Craft::$app->getRequest()->getRequiredParam('requestId');

        /** @var BulkEditService $service */
        $service = Plugin::$plugin->bulkEdit;
        $fields = $service->getFieldsForElementIds($elementIds);


        $view = \Craft::$app->getView();
        $modalHtml = $view->renderTemplate('venveo-bulk-edit/elementactions/BulkEdit/_fields', [
            'fieldWrappers' => $fields,
            'bulkedit' => $service,
            'elementIds' => $elementIds,
            'site' => $site
        ]);

        $responseData = [
            'success' => true,
            'modalHtml' => $modalHtml,
            'requestId' => $requestId,
            'elementIds' => $elementIds,
            'siteId' => $site->id
        ];
        $responseData['headHtml'] = $view->getHeadHtml();
        $responseData['footHtml'] = $view->getBodyHtml();


        return $this->asJson($responseData);
    }

    public function actionGetEditScreen(): Response
    {
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();


        $elementIds = array_values(Craft::$app->getRequest()->getRequiredParam('elementIds'));
        $requestId = Craft::$app->getRequest()->getRequiredParam('requestId');
        $siteId = Craft::$app->getRequest()->getRequiredParam('siteId');
        $fields = Craft::$app->getRequest()->getRequiredParam('fields');

        // Pull out the enabled fields
        $enabledFields = [];
        foreach($fields as $fieldId => $field) {
            if ($field['enabled']) {
                $enabledFields[$fieldId] = $field;
            }
        }


        $site = Craft::$app->getSites()->getSiteById($siteId);
        if (!$site) {
            throw new \Exception('Site does not exist');
        }


        $fields = Field::findAll(array_keys($enabledFields));
        if (count($fields) !== count($enabledFields)) {
            throw new \Exception('Could not find all fields requested');
        }

        $elements = Element::findAll($elementIds);
        if (count($elements) !== count($elementIds)) {
            throw new \Exception('Could not find all elements requested');
        }

        try {
            $fieldModels = [];
            /** @var Field $field */
            foreach ($fields as $field) {
                $fieldModel = \Craft::$app->fields->getFieldById($field->id);
                if ($fieldModel && BulkEdit::$plugin->bulkEdit->isFieldSupported($fieldModel)) {
                    $fieldModels[] = $fieldModel;
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $view = \Craft::$app->getView();

        // We've gotta register any asset bundles - this won't actually be rendered
        foreach($fieldModels as $fieldModel) {
            $view->renderPageTemplate('_includes/field', [
                'field' => $fieldModel,
                'required' => false
            ]);
        }

        $modalHtml = $view->renderTemplate('venveo-bulk-edit/elementactions/BulkEdit/_edit', [
            'fields' => $fieldModels,
            'elementIds' => $elementIds,
            'fieldData' => $enabledFields,
            'site' => $site
        ]);
        $responseData = [
            'success' => true,
            'modalHtml' => $modalHtml,
            'requestId' => $requestId,
            'siteId' => $site->id
        ];
        $responseData['headHtml'] = $view->getHeadHtml();
        $responseData['footHtml'] = $view->getBodyHtml();

        return $this->asJson($responseData);
    }

    public function actionSaveContext(): Response
    {
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementIds = Craft::$app->getRequest()->getRequiredParam('elementIds');
        $siteId = Craft::$app->getRequest()->getRequiredParam('siteId');
//        $fieldIds = array_values(Craft::$app->getRequest()->getRequiredParam('fieldIds'));
        $fieldMeta = array_values(Craft::$app->getRequest()->getRequiredParam('fieldMeta'));

        $fieldStrategies = [];
        foreach($fieldMeta as $field) {
            $fieldStrategies[$field['id']] = $field['strategy'];
        }
        $fieldIds = array_keys($fieldStrategies);
        $fields = Field::findAll($fieldIds);

        $values = Craft::$app->getRequest()->getBodyParam('fields', []);

        $keyedFieldValues = [];
        foreach ($values as $handle => $value) {
            foreach ($fields as $field) {
                if ($field->handle === $handle) {
                    $fieldId = $field->id;
                }
            }
            if (!isset($fieldId)) {
                throw new \Exception('Failed to locate field');
            }
            $keyedFieldValues[$fieldId] = $value;
        }

        $context = new EditContext();
        $context->ownerId = \Craft::$app->getUser()->getIdentity()->id;
        $context->siteId = $siteId;
        $context->elementIds = \GuzzleHttp\json_encode($elementIds);
        $context->fieldIds = \GuzzleHttp\json_encode($fieldIds);
        $context->save();

        $rows = [];
        foreach ($elementIds as $elementId) {
            foreach ($fieldIds as $fieldId) {
                $strategy = $fieldStrategies[$fieldId] ?? 'replace';

                $rows[] = [
                    'pending',
                    $context->id,
                    (int)$elementId,
                    (int)$fieldId,
                    (int)$siteId,
                    '[]',
                    \GuzzleHttp\json_encode($keyedFieldValues[$fieldId]),
                    $strategy
                ];
            }
        }

        $cols = ['status', 'contextId', 'elementId', 'fieldId', 'siteId', 'originalValue', 'newValue', 'strategy'];
        \Craft::$app->db->createCommand()->batchInsert(History::tableName(), $cols, $rows)->execute();


        $job = new SaveBulkEditJob([
            'context' => $context
        ]);
        \Craft::$app->getQueue()->push($job);

        return $this->asJson([
            'success' => true
        ]);
    }
}
