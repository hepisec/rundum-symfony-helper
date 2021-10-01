<?php

namespace Rundum\SymfonyHelperBundle\Util;

use Rundum\SymfonyHelperBundle\Util\DefaultPagerAdapterFactory;
use Rundum\SymfonyHelperBundle\Util\PagerAdapterFactoryInterface;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment as TwigEnvironment;

/**
 *
 * @author hendrik
 * @author ldommer
 */
class DataListBuilder {

    private $twig;
    private $formFactory;
    private $request = null;

    /* @var $queryBuilder QueryBuilder */
    private $queryBuilder = null;
    private $pagerAdapterFactory = null;
    private $countCallable = null;
    private $listTemplate = 'datalist/list.html.twig';
    private $title = '';
    private $sortEnabled = true;
    private $filterEnabled = true;
    private $templateOptions = [];
    private $offset = 1;
    private $limit = 100;

    public function __construct(
            TwigEnvironment $twig,
            FormFactoryInterface $formFactory,
            Request $request = null
    ) {
        $this->twig = $twig;
        $this->formFactory = $formFactory;
        $this->request = $request;
    }

    public function setRequest(Request $request) {
        $this->request = $request;
        return $this;
    }

    public function setPagerAdapterFactory(PagerAdapterFactoryInterface $pagerAdapterFactory) {
        $this->pagerAdapterFactory = $pagerAdapterFactory;
        return $this;
    }

    public function setCountCallable(callable $countCallable) {
        $this->countCallable = $countCallable;
        return $this;
    }

    public function useSelectCount() {
        $this->countCallable = [$this, 'selectCount'];
        return $this;
    }

    public function setListTemplate($template) {
        $this->listTemplate = $template;
        return $this;
    }

    public function setTitle($title) {
        $this->title = $title;
        return $this;
    }

    public function disableSort() {
        $this->sortEnabled = false;
        return $this;
    }

    public function disableFilter() {
        $this->filterEnabled = false;
        return $this;
    }

    public function setTemplateOptions($templateOptions) {
        $this->templateOptions = $templateOptions;
        return $this;
    }

    public function setOffset($offset) {
        $this->offset = $offset;
        return $this;
    }

    public function setLimit($limit) {
        $this->limit = $limit;
        return $this;
    }

    public function buildList(QueryBuilder $queryBuilder) {
        $this->queryBuilder = $queryBuilder;
        $appliedFilters = $this->filter();
        $this->order();

        if ($this->pagerAdapterFactory === null) {
            $this->pagerAdapterFactory = new DefaultPagerAdapterFactory();
        }

        if ($this->request !== null) {
            if ($this->request->query->get('csv', null) !== null) {
                $response = new StreamedResponse(function () use ($queryBuilder) {
                    $this->csv($queryBuilder);
                }, 200, ['Content-Type' => 'text/csv']);

                return $response;
            }

            $this->offset = $this->request->query->getInt('page', 1);
            $this->limit = $this->request->query->getInt('max', $this->limit);
        }

        if ($this->countCallable === null || !is_callable($this->countCallable)) {
            $adapter = $this->pagerAdapterFactory->newAdapter($this->queryBuilder, [$this, 'simpleCount']);
        } else {
            $adapter = $this->pagerAdapterFactory->newAdapter($this->queryBuilder, $this->countCallable);
        }

        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage($this->limit)
                ->setCurrentPage($this->offset);

        $content = $this->twig->render($this->listTemplate, array_merge([
            'title' => $this->title,
            'pager' => $pager,
            'appliedFilters' => $appliedFilters,
            'sortEnabled' => $this->sortEnabled,
            'filterEnabled' => $this->filterEnabled,
                        ], $this->templateOptions));

        return new Response($content);
    }

    private function filter() {
        if ($this->request === null || $this->queryBuilder === null) {
            return [];
        }

        $filter = $this->request->query->get('filter');
        $filterValue = $this->request->query->get('value');

        $filter = is_array($filter) ? $filter : [$filter];
        $filterValue = is_array($filterValue) ? $filterValue : [$filterValue];

        $filterColumns = $this->getColumns($this->queryBuilder->getQueryPart('select'));

        $filtersToApply = [];

        for ($i = 0; $i < count($filter); $i++) {
            if (!isset($filterColumns[$filter[$i]])) {
                continue;
            }

            $filtersToApply[$filter[$i]] = $filterValue[$i];
        }

        $j = 0;

        foreach ($filtersToApply as $col => $val) {
            if (empty($val) && $val !== '0') {
                unset($filtersToApply[$col]);
                continue;
            }

            $operator = 'LIKE';
            $matches = [];

            if (preg_match('/^> *([^=].*)$/', $val, $matches) === 1) {
                $operator = '>';
                $val = $matches[1];
            } elseif (preg_match('/^>= *(.*)$/', $val, $matches) === 1) {
                $operator = '>=';
                $val = $matches[1];
            } elseif (preg_match('/^< *([^=>].*)$/', $val, $matches) === 1) {
                $operator = '<';
                $val = $matches[1];
            } elseif (preg_match('/^<= *(.*)$/', $val, $matches) === 1) {
                $operator = '<=';
                $val = $matches[1];
            } elseif (preg_match('/^==? *(.*)$/', $val, $matches) === 1) {
                $operator = '=';
                $val = $matches[1];

                if ($val === '""' || $val === "''") {
                    $val = '';
                }
            } elseif (preg_match('/^(!=|<>)\\* *(.*)$/', $val, $matches) === 1) {
                $operator = 'NOT LIKE';
                $val = $matches[2];
            } elseif (preg_match('/^(!=|<>) *(.*)$/', $val, $matches) === 1) {
                $operator = '!=';
                $val = $matches[2];

                if ($val === '""' || $val === "''") {
                    $val = '';
                }
            }

            if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                if (strpos($val, '*') !== false) {
                    $val = str_replace('*', '%', $val);
                } else {
                    $val = '%' . $val . '%';
                }
            }

            $expression = $filterColumns[$col] . ' ' . $operator . ' :filterValue' . $j;

            if ($operator === '=' && empty($val)) {
                $expression .= ' OR ' . $filterColumns[$col] . ' IS NULL';
            } elseif ($operator === '!=' && empty($val)) {
                $expression .= ' OR ' . $filterColumns[$col] . ' IS NOT NULL';
            }

            if ($this->isGroupColumn($filterColumns[$col])) {
                $this->queryBuilder->andHaving($expression);
            } else {
                $this->queryBuilder->andWhere($expression);
            }

            $this->queryBuilder->setParameter(':filterValue' . $j, $val);

            $j++;
        }

        return $filtersToApply;
    }

    private function getColumns($selectParts) {
        $columns = [];

        foreach ($selectParts as $selectPart) {
            $exp = explode(' AS ', $selectPart);

            if (count($exp) === 2) {
                $columns[str_replace("'", '', $exp[1])] = $exp[0];
            } else {
                $columns[$exp[0]] = $exp[0];
            }
        }

        return $columns;
    }

    private function order() {
        if ($this->request === null || $this->queryBuilder === null) {
            return;
        }

        $sort = $this->request->query->get('sort');
        $order = $this->request->query->get('order', 'asc');

        if ($sort === null) {
            return;
        }

        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        $orderColumns = $this->getColumns($this->queryBuilder->getQueryPart('select'));

        if (!isset($orderColumns[$sort])) {
            return;
        }

        $this->queryBuilder->orderBy($orderColumns[$sort], $order);
    }

    public function simpleCount(QueryBuilder $qb) {
        $qb->select('COUNT(*)');
    }

    public function selectCount(QueryBuilder $qb) {
        $countQueryBuilder = clone $qb;
        $countQueryBuilder->add('orderBy', [], false);

        $sql = $countQueryBuilder->getSQL();

        $qb->resetQueryParts();
        $qb->select('COUNT(*)')
                ->from('(' . $sql . ')', 'origin');
    }

    private function csv(QueryBuilder $qb): void {
        $stmt = $qb->getConnection()->getWrappedConnection()->prepare($qb->getSQL());
        $stmt->execute($qb->getParameters());
        $i = 0;
        $flushRows = 1000;
        $csv = fopen('php://output', 'w');

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            unset($row['action']);

            if ($i === 0) {
                fputcsv($csv, array_keys($row), ';');
            }

            fputcsv($csv, $row, ';');
            $i++;

            if ($i % $flushRows === 0) {
                flush();
            }
        }

        fclose($csv);
    }

    private function isGroupColumn($column) {
        if (strpos($column, '(') < 1) {
            return false;
        }

        $colFunction = substr($column, 0, strpos($column, '('));

        $groupFunctions = [
            'COUNT',
            'GROUP_CONCAT',
            'MAX',
            'MIN',
            'SUM',
        ];

        if (in_array($colFunction, $groupFunctions)) {
            return true;
        }

        foreach ($groupFunctions as $groupFunction) {
            if (strpos($column, $groupFunction . '(') !== false) {
                return true;
            }
        }

        return false;
    }

}
