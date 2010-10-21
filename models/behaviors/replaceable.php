<?php

/**
 * Behavior for "moving" a replaced model row to a history/audit table.
 *
 * MUST BE DYNAMICALLY LOADED.  Don't uses the $actsAs variable.  Rather, set it with attach() in the edit methods.
 * This is required because the model ID must be set before use in order to get the data we want.
 *
 * Settings:
 *		history_row_field_name:
 *			required; the field name on the model for storing the row in the history table.
 *		created:
 *			optional; the field name in which to store the original data's created datetime;
 *			can't be 'created' because cake would overwrite that automatically
 *		modified:
 *			optional; the field name in which to store the original data's modified datetime;
 *			can't be 'modified' because cake would overwrite that automatically
 *
 * Runs automatically before Model::afterSave and sets flags on the model for determining
 * success or failure and handling conditions.
 */
class ReplaceableBehavior extends ModelBehavior
{
	/**
	 * Setup
	 * 
	 * @param object $Model
	 * @param array $settings The 'history_row_field_name' is required.  Optionally include 'created' and/or 'modified'.
	 * @return meaningless Instead of using the return value, test for Model->ReplaceableBehaviorIsSetUp 
	 */
	function setup(&$Model, $settings = array())
	{
		$Model->ReplaceableBehaviorIsSetUp = false;
		$Model->ReplaceableBehavior_updatingHistoryRow = false;
		$Model->ReplaceableBehavior_replacementSuccess = false;

		$this->ReplacedModelName = 'Replaced'.$Model->alias;
		$this->UnderscoredModelName = Inflector::underscore($Model->alias);
		$this->HumanizedModelName = Inflector::humanize($this->UnderscoredModelName);
		$this->ModelIdFieldName = $this->UnderscoredModelName.'_id';

		$this->settings = $settings;
		
		if (!isset($this->settings['history_row_field_name']) || empty($this->settings['history_row_field_name']))
		{
			$Model->ReplaceableBehaviorMessages['Error'] = 'ReplaceableBehavior setup failure: The field name for the '.$Model->alias.' table history row must be set.';
			return false;
		}
		else
		{
			if (!$Model->id)
			{
				$Model->ReplaceableBehaviorMessages['Error'] = 'ReplaceableBehavior setup failure: Model ID must be set.';
				return false;
			}
			else
			{
				$Model->recursive = -1;
				// Required: find() instead of read()
				// read() gets the data, but also fetches the modified/created fields, which means they're set back to original on save
				$this->OriginalData = $Model->find('first', array('conditions' => array("{$Model->alias}.id" => $Model->id)));
				if (empty($this->OriginalData))
				{
					$Model->ReplaceableBehaviorMessages['Error'] = 'ReplaceableBehavior setup failure: Failed to capture original '.$Model->alias.' data.';
					return false;
				}
				else
				{
					$Model->ReplaceableBehaviorMessages['Debug'] = 'Original '.$Model->alias.' data captured.';
					$Model->ReplaceableBehaviorIsSetUp = true;
				}
			}
		}
	}

	function afterSave(&$Model, $created)
	{
		// Don't re-enter when saving the history row field - infinite loop!
		if (!$Model->ReplaceableBehavior_updatingHistoryRow)
		{
			$dataReplaced = $this->OriginalData[$Model->alias];
			if (isset($this->settings['created']))
			{
				$dataReplaced[$this->settings['created']] = $dataReplaced['created'];
				unset($dataReplaced['created']);
			}
			if (isset($this->settings['modified']))
			{
				$dataReplaced[$this->settings['modified']] = $dataReplaced['modified'];
				unset($dataReplaced['modified']);
			}
			$id = $dataReplaced['id'];
			unset($dataReplaced['id']);

			$dataReplaced[$this->ModelIdFieldName] = $this->OriginalData[$Model->alias]['id'];
			$dataToSave[$this->ReplacedModelName] = $dataReplaced;

			if (!$Model->{$this->ReplacedModelName}->save($dataToSave))
			{
				$Model->ReplaceableBehaviorMessages['Error'] = 'The '.$this->HumanizedModelName.' was not saved: the audit data could not be saved.';
				// TODO RTMS: Log it
			}
			else
			{
				// update the history row
				$history_row = $Model->{$this->ReplacedModelName}->getInsertId();

				$Model->id = $id;
				$Model->ReplaceableBehavior_updatingHistoryRow = true;
				// Save the model data, otherwise it's lost in the saveField call below.
				$this->originalModelData = $Model->data;
				if (!$Model->saveField($this->settings['history_row_field_name'], $history_row, true))
				{
					$Model->ReplaceableBehaviorMessages['Error'] = 'The '.$this->HumanizedModelName.' was not saved: the audit row ('.$history_row.') could not be saved.';
					// TODO RTMS: Log it
				}
				else
				{
					$Model->ReplaceableBehavior_replacementSuccess = true;
				}
				// Restore the model data saved above.
				$Model->data = $this->originalModelData;
			}
		}
	}
}
?>
