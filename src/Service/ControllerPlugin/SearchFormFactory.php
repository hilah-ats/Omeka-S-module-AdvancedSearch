<?php declare(strict_types=1);
namespace AdvancedSearch\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use AdvancedSearch\Mvc\Controller\Plugin\AdvancedSearchForm;

class SearchFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new SearchForm(
            $services->get('ViewHelperManager')->get('searchForm')
        );
    }
}
