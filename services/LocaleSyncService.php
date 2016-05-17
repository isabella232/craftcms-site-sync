<?php
namespace Craft;

class LocaleSyncService extends BaseApplicationComponent
{
	public $elementBeforeSave;
	public $element;
	public $elementSettings;

	public function getElementOptionsHtml(BaseElementModel $element)
	{
		$isNew = $element->id === null;
		$locales = array_keys($element->getLocales());
		$settings = craft()->plugins->getPlugin('localeSync')->getSettings();

		if ($isNew || count($locales) < 2) {
			return;
		}

		$targets = [
			'options' => craft()->localeSync->getLocaleInputOptions($locales, [$element->locale]),
			'values' => isset($settings->defaultTargets[$element->locale]) ? $settings->defaultTargets[$element->locale] : [],
		];

		return craft()->templates->render('localesync/_cp/entriesEditRightPane', [
			'targets' => $targets,
			'enabled' => (bool) count($targets['values']),
		]);
	}

	public function getLocaleInputOptions($locales = null, $exclude = [])
	{
		$locales = $locales ?: craft()->i18n->getSiteLocales();
		$locales = array_map(function($locale) use ($exclude) {
			if (!$locale instanceof LocaleModel) {
				$locale = craft()->i18n->getLocaleById($locale);
			}

			if ($locale instanceof LocaleModel && !in_array($locale->id, $exclude)) {
				$locale = [
					'label' => $locale->name,
					'value' => $locale->id,
				];
			} else {
				$locale = null;
			}

			return $locale;
		}, $locales);

		return array_filter($locales);
	}

	public function syncElementContent(Event $event, $elementSettings)
	{
		$pluginSettings = craft()->plugins->getPlugin('localeSync')->getSettings();
		$this->element = $event->params['element'];
		$this->elementSettings = $elementSettings;

		// elementSettings will be null in HUD
		if ($this->elementSettings !== null && ($event->params['isNewElement'] || empty($this->elementSettings['enabled']))) {
			return;
		}

		$this->elementBeforeSave = craft()->elements->getElementById($this->element->id, $this->element->elementType, $this->element->locale);
		$locales = $this->element->getLocales();
		$defaultTargets = array_key_exists($this->element->locale, $pluginSettings->defaultTargets) ? $pluginSettings->defaultTargets[$this->element->locale] : [];
		$elementTargets = $this->elementSettings['targets'];
		$targets = [];

		if (!empty($elementTargets)) {
			$targets = $elementTargets;
		} elseif (!empty($defaultTargets)) {
			$targets = $defaultTargets;
		}

		foreach ($locales as $localeId => $localeInfo)
		{
			$localizedElement = craft()->elements->getElementById($this->element->id, $this->element->elementType, $localeId);
			$matchingTarget = $targets === '*' || in_array($localeId, $targets);
			$updates = false;

			if ($localizedElement && $matchingTarget && $this->element->locale !== $localeId) {
				foreach ($localizedElement->getFieldLayout()->getFields() as $fieldLayoutField) {
					$field = $fieldLayoutField->getField();

					if ($this->updateElement($localizedElement, $field)) {
						$updates = true;
					}
				}

				if ($this->updateElement($localizedElement, 'title')) {
					$updates = true;
				}

			}

			if ($updates) {
				craft()->content->saveContent($localizedElement, false, false);

				if ($localizedElement instanceof EntryModel) {
					craft()->entryRevisions->saveVersion($localizedElement);
				}
			}
		}
	}

	public function updateElement(&$element, $field)
	{
		$update = false;

		if ($field instanceof Fieldmodel) {
			$fieldHandle = $field->handle;
			$translatable = $field->translatable;
		} elseif ($field === 'title') {
			$fieldHandle = $field;
			$translatable = true;
		}

		$matches = $this->elementBeforeSave->content->$fieldHandle === $element->content->$fieldHandle;
		$overwrite = (isset($this->elementSettings['overwrite']) && $this->elementSettings['overwrite']);
		$updateField = $overwrite || $matches;

		if ($updateField && $translatable) {
			$element->content->$fieldHandle = $this->element->content->$fieldHandle;
			$update = true;
		}

		return $update;
	}
}
