<?php

namespace Rundum\SymfonyHelperBundle\Service;

use Rundum\SymfonyHelperBundle\Util\DataListBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment as TwigEnvironment;

/**
 *
 * @author hendrik
 * @author ldommer
 */
class DataListService {

    private $twig;
    private $formFactory;

    public function __construct(
        TwigEnvironment $twig,
        FormFactoryInterface $formFactory
    ) {
        $this->twig = $twig;
        $this->formFactory = $formFactory;
    }

    /**
     * @return EntityResponseBuilder
     */
    public function builder(Request $request = null) {
        return new DataListBuilder($this->twig, $this->formFactory, $request);
    }

}
