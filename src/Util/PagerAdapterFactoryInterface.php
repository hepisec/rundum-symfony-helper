<?php

namespace Rundum\SymfonyHelperBundle\Util;

use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Adapter\AdapterInterface;

/**
 * Description of PagerAdapterFactoryInterface
 *
 * @author hendrik
 */
interface PagerAdapterFactoryInterface {
    public function newAdapter(QueryBuilder $qb, callable $countCallable): AdapterInterface;
}
