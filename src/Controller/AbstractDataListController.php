<?php

namespace Rundum\SymfonyHelperBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Rundum\Event\EntityChangeIntendedEvent;
use Rundum\Event\EntityRemovalIntendedEvent;
use Rundum\SymfonyHelperBundle\Service\DataListService;
use Rundum\SymfonyHelperBundle\Service\EventService;
use Rundum\SymfonyHelperBundle\Util\DataListBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractDataListController extends AbstractController {

    protected $doctrine;
    protected $dataListService;
    protected $eventService;
    protected $translator;

    public function __construct(
            ManagerRegistry $doctrine,
            DataListService $dataListService,
            EventService $eventService,
            TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->dataListService = $dataListService;
        $this->eventService = $eventService;
        $this->translator = $translator;
    }

    abstract protected function getEntityClass(): string;

    abstract protected function getEntityName(): string;

    abstract protected function findAll(Request $request): QueryBuilder;

    protected function getRepository(): ObjectRepository {
        return $this->doctrine->getRepository($this->getEntityClass());
    }

    protected function isDeletable(): bool {
        return false;
    }

    protected function getDeleteEvents($entity): array {
        return [new EntityRemovalIntendedEvent($entity)];
    }

    protected function getAjaxButton(): ?string {
        return null;
    }

    protected function getFormOptions(Request $request): array {
        return [];
    }

    protected function isCreateRoute(Request $request) {
        return strpos($request->get('_route'), 'create') !== false;
    }

    protected function isUpdateRoute(Request $request) {
        return strpos($request->get('_route'), 'update') !== false;
    }

    protected function getCreateTemplate(): string {
        return '@RundumSymfonyHelper/create.html.twig';
    }

    protected function getListTemplate(): string {
        return '@RundumSymfonyHelper/datalist/list.html.twig';
    }

    protected function getPostEvents(Request $request, $entity): array {
        return $this->isCreateRoute($request) === true ? $this->getCreatedEvents($entity) : $this->getUpdatedEvents($entity);
    }

    protected function getCreatedEvents($entity): array {
        return [new EntityChangeIntendedEvent($entity, true)];
    }

    protected function getUpdatedEvents($entity): array {
        return [new EntityChangeIntendedEvent($entity, false)];
    }

    protected function getLimit(): int {
        return 25;
    }

    /**
     * @Route("", methods={"GET"}, name="list")
     */
    public function list(Request $request) {
        $builder = $this->dataListService
                ->builder($request)
                ->setTitle($this->translator->trans($this->getEntityName() . '.datalist.title'))
                ->setListTemplate($this->getListTemplate())
                ->setTemplateOptions(['create_route' => $this->getRoute($this->getEntityClass(), 'create')])
                ->setLimit($this->getLimit());

        $this->configureDataListBuilder($builder);

        return $builder->buildList($this->findAll($request));
    }

    protected function configureDataListBuilder(DataListBuilder $builder) {

    }

    /**
     * @Route("/create", methods={"GET", "POST"}, name="create")
     */
    public function create(Request $request) {
        $entity = (new \ReflectionClass($this->getEntityClass()))->newInstance();
        $this->setPermissionForCreate($entity);

        return $this->post($request, $entity);
    }

    /**
     * @Route("/{id}/update", methods={"GET", "POST"}, name="update", requirements={"id": "\d+"})
     */
    public function update(Request $request, string $id) {
        $entity = $this->getRepository()->find($id);

        if (empty($entity) === true) {
            $this->createNotFoundException();
        }

        $this->setPermissionForUpdate($entity);

        if ($this->isDeletable() === true) {
            $form = $this->getDeleteForm($entity);
            $form->handleRequest($request);
        }

        return $this->post($request, $entity);
    }

    /**
     * @Route("/{id}/delete", methods={"POST", "DELETE"}, name="delete", requirements={"id": "\d+"})
     */
    public function delete(Request $request, string $id) {
        $entity = $this->getRepository()->find($id);

        if (empty($entity) === true) {
            $this->createNotFoundException();
        }
        $this->setPermissionForDelete($entity);
        $redirect = $this->getRedirect();

        if ($this->isDeletable() === true) {
            return $this->buildPost(
                            $request,
                            $entity,
                            $this->getDeleteForm($entity),
                            $this->getDeleteEvents($entity),
                            $redirect
            );
        }

        return $redirect;
    }

    protected function getRoute(string $entityClass, string $type): string {
        return $this->getRouteName($entityClass) . '_' . $type;
    }

    protected function getRouteName(string $entityClass): string {
        return strtolower(implode('_', preg_split('/(?=[A-Z])/', $this->getShortName($entityClass), 0, PREG_SPLIT_NO_EMPTY)));
    }

    private function getShortName(string $entityClass): string {
        $reflectionClass = new \ReflectionClass($entityClass);

        return $reflectionClass->getShortName();
    }

    protected function post(Request $request, $entity) {
        return $this->buildPost(
                        $request,
                        $entity,
                        $this->getForm($request, $entity),
                        $this->getPostEvents($request, $entity),
                        $this->getRedirect()
        );
    }

    protected function buildPost(
            Request $request,
            $entity,
            FormInterface $form,
            array $events,
            RedirectResponse $redirect
    ) {
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->processForm($request, $entity, $form);
            $this->eventService->dispatchAll($events);

            if ($form->has('__referrer') && $form->get('__referrer')->getData() !== null) {
                return new RedirectResponse($form->get('__referrer')->getData());
            }

            return $redirect;
        }

        return $this->render(
                        $this->getCreateTemplate($request, $entity),
                        array_merge($this->getTemplateOptions($request, $entity), ['form' => $form->createView()])
        );
    }

    protected function processForm(Request $request, $entity, FormInterface $form) {

    }

    protected function getDeleteForm($entity) {
        $formBuilder = $this->get('form.factory')->createNamedBuilder(
                $this->getRoute($this->getEntityClass(), 'delete'),
                FormType::class,
                null,
                ['method' => 'POST']
        );

        $formBuilder
                ->setAction($this->generateUrl($this->getRoute($this->getEntityClass(), 'delete'), ['id' => $entity->getId()]))
                ->setMethod('POST');

        return $formBuilder->getForm();
    }

    private function getRedirect(): RedirectResponse {
        return $this->redirectToRoute($this->getRoute($this->getEntityClass(), 'list'));
    }

    private function getDeleteButton(Request $request, $entity): ?string {
        if ($this->isCreateRoute($request) === false && $this->isDeletable() === true) {
            return $this->renderView('@RundumSymfonyHelper/delete_button.html.twig', [
                        'form' => $this->getDeleteForm($entity)->createView(),
            ]);
        }

        return null;
    }

    private function getForm(Request $request, object $entity) {
        $form = $this->createForm(
                $this->getFormClass($request, $this->getEntityClass()),
                $entity,
                $this->getFormOptions($request)
        );
        $form->add(
                '__referrer',
                HiddenType::class,
                [
                    'mapped' => false
                ]
        );
        $form->get('__referrer')->setData($request->headers->get('referer'));
        return $form;
    }

    private function getFormClass(Request $request, string $entity): string {
        if ($this->isUpdateRoute($request)) {
            return $this->getUpdateFormClass($entity);
        }
        return $this->getCreateFormClass($entity);
    }

    protected function getCreateFormClass(string $entityClass): string {
        return 'App\\Form\\' . $this->getShortName($entityClass) . 'Type';
    }

    protected function getUpdateFormClass(string $entityClass): string {
        return $this->getCreateFormClass($entityClass);
    }

    protected function getTemplateOptions(Request $request, $entity): array {
        return [
            'headline' => $this->translator->trans($this->getEntityName() . ' ' . ($this->isCreateRoute($request) === true ? 'create' : 'edit')),
            'listRoute' => $this->getRoute($this->getEntityClass(), 'list'),
            'ajaxButton' => $this->getAjaxButton(),
            'deleteButton' => $this->getDeleteButton($request, $entity),
            lcfirst($this->getShortName($this->getEntityClass())) => $entity
        ];
    }

    protected function setPermissionForCreate($entity) {
        $this->denyAccessUnlessGranted('CREATE', $entity);
    }

    protected function setPermissionForUpdate($entity) {
        $this->denyAccessUnlessGranted('UPDATE', $entity);
    }

    protected function setPermissionForDelete($entity) {
        $this->denyAccessUnlessGranted('DELETE', $entity);
    }

}
