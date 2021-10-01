<?php

namespace Rundum\SymfonyHelperBundle\Util;

use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Adapter\AdapterInterface;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;

/**
 * Description of DefaultPagerAdapterFactory
 *
 * @author hendrik
 */
class DefaultPagerAdapterFactory implements PagerAdapterFactoryInterface {

    public function newAdapter(QueryBuilder $qb, callable $countCallable): AdapterInterface {
        return new QueryAdapter($qb, $countCallable);
    }

}
