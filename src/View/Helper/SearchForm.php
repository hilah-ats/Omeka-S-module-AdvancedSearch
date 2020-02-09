<?php

namespace Search\View\Helper;

use Search\Api\Representation\SearchPageRepresentation;
use Zend\Form\Form;
use Zend\View\Helper\AbstractHelper;

class SearchForm extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'search/search-form';

    /**
     * @var SearchPageRepresentation
     */
    protected $searchPage;

    /**
     * @var string
     */
    protected $partial;

    /**
     * @var Form
     */
    protected $form;

    /**
     * @param SearchPageRepresentation $searchPage
     * @param string $partial Specific partial for the search form.
     * @return \Search\View\Helper\SearchForm
     */
    public function __invoke(SearchPageRepresentation $searchPage = null, $partial = null)
    {
        $this->searchPage = $searchPage;
        $this->partial = $partial;
        $this->form = null;
        return $this;
    }

    public function __toString()
    {
        $formPartial = $this->getFormPartial();
        if (empty($formPartial)) {
            return '';
        }
        $form = $this->getForm();
        if (empty($form)) {
            return '';
        }
        return $this->getView()->partial($formPartial, ['form' => $form]);
    }

    /**
     * @return \Zend\Form\Form|null
     */
    public function getForm()
    {
        if (empty($this->searchPage)) {
            return null;
        }
        if (empty($this->form)) {
            $this->form = $this->searchPage->form();
            if (empty($this->form)) {
                return null;
            }
            $url = $this->getView()->params()->fromRoute('__ADMIN__')
                ? $this->searchPage->adminSearchUrl()
                : $this->searchPage->url();
            $this->form->setAttribute('action', $url);
        }
        return $this->form;
    }

    /**
     * Get the partial form used for this form.
     *
     * @return string
     */
    protected function getFormPartial()
    {
        if (empty($this->searchPage)) {
            return '';
        }
        if ($this->partial) {
            return $this->partial ;
        }
        $formAdapter = $this->searchPage->formAdapter();
        return $formAdapter && ($formPartial = $formAdapter->getFormPartial())
            ? $formPartial
            : self::PARTIAL_NAME;
    }
}
