<?php
/*
 * This file is part of the Manuel Aguirre Project.
 *
 * (c) Manuel Aguirre <programador.manuel@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ManuelAguirre\Bundle\TranslationBundle\Controller;

use ManuelAguirre\Bundle\TranslationBundle\Entity\Translation;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * @author Manuel Aguirre <programador.manuel@gmail.com>
 */
class TranslationController extends Controller
{
    /**
     * @Route("/list/{page}", name="manuel_translation_list", defaults={"page" = 1})
     */
    public function indexAction(Request $request, $page = 1)
    {
        $session = $this->get('session');
        $filters = $session->get('manuel_translations.trans_filter', array(
            'search' => null,
            'inactive' => false,
            'domains' => array('messages'),
        ));

        $formFilter = $this->createForm('translation_filter', $filters, array('method' => 'post'))
            ->handleRequest($request);


        if ($formFilter->isSubmitted()) {
            $filters = $formFilter->getData();
            $session->set('manuel_translations.trans_filter', $filters);
        }

        $query = $this->getDoctrine()
            ->getRepository('ManuelTranslationBundle:Translation')
            ->getAllQueryBuilder($filters['search'], $filters['domains'], $filters['inactive']);

        $form = $this->createForm('manuel_translation', $this->getNewTranslationInstance());

        $paginator = new Pagerfanta(new DoctrineORMAdapter($query, false));
        $paginator->setMaxPerPage(50);
        $paginator->setCurrentPage($page);

        return $this->render('@ManuelTranslation/Default/index.html.twig', array(
            'translations' => $paginator,
            'form' => $form->createView(),
            'locales' => $this->container->getParameter('manuel_translation.locales'),
            'form_filter' => $formFilter->createView(),
        ));
    }

    /**
     * @Route("/remove-filters", name="manuel_translation_remove_filters")
     */
    public function clearFiltersAction()
    {
        $this->get('session')->remove('manuel_translations.trans_filter');

        return $this->redirectToRoute('manuel_translation_list');
    }

    /**
     * @Route("/form/{id}", name="manuel_translation_form", defaults={"id" = null})
     */
    public function editAction(Request $request, Translation $translation = null)
    {
        $translation = $translation ?: $this->getNewTranslationInstance();

        $form = $this->createForm('manuel_translation', $translation)
            ->handleRequest($request);

        if ($form->isSubmitted() and $form->isValid()) {

            $this->get('manuel_translation.translations_repository')->saveTranslation($translation);

            $filesystem = new Filesystem();
            $filenameTemplate = $this->container->getParameter('manuel_translation.filename_template');

            foreach ($translation->getValues() as $locale => $value) {
                $filename = sprintf($filenameTemplate, $locale);
                $filesystem->dumpFile($filename, time());
            }

            $saved = true;
        } else {
            $saved = false;
        }

        $response = $this->render('@ManuelTranslation/Translation/form.html.twig', array(
            'form' => $form->createView(),
        ));

        $response->headers->set('saved', $saved);

        return $response;
    }

    /**
     * @Route("/show/item/{id}", name="manuel_translation_show_item")
     */
    public function getTranslationItemAction(Translation $translation)
    {
        return $this->render('@ManuelTranslation/Translation/_row.html.twig', array(
            'translation' => $translation,
            'locales' => $this->container->getParameter('manuel_translation.locales'),
        ));
    }

    /**
     * @Route("/save-from-profiler", name="manuel_translation_save_from_profiler")
     */
    public function saveFromProfilerAction(Request $request)
    {
        $translation = $this->getNewTranslationInstance();
        $translation->setCode($request->request->get('code'));
        $translation->setDomain($request->request->get('domain'));

        foreach ($request->request->get('values', array()) as $locale => $value) {
            $translation->setValue($locale, $value);
        }

        if (count($this->get('validator')->validate($translation)) == 0) {
            $this->get('manuel_translation.translations_repository')->saveTranslation($translation);

            $filesystem = new Filesystem();
            $filenameTemplate = $this->container->getParameter('manuel_translation.filename_template');

            foreach ($translation->getValues() as $locale => $value) {
                $filename = sprintf($filenameTemplate, $locale);
                $filesystem->dumpFile($filename, time());
            }
        }


        return new Response('Ok');
    }

    /**
     * @return Translation
     */
    protected function getNewTranslationInstance()
    {
        $translation = new Translation();
        $translation->setNew(false);
        $translation->setAutogenerated(false);
        $translation->setActive(true);

        foreach ($this->container->getParameter('manuel_translation.locales') as $locale) {
            $translation->setValue($locale, null);
        }

        return $translation;
    }

    /**
     * @Route("/update_all", name="manuel_translation_export")
     */
    public function updateXliffAction($_locale)
    {
        $catalogue = $this->get('manuel_translation.translations_doctrine_loader')
            ->load('', $_locale);

        $path = $this->container->getParameter('manuel_translation.translations_update_dir');

        $this->get('translation.dumper.xliff')->dump($catalogue, array(
            'path' => $path,
        ));

        return $this->redirectToRoute('manuel_translation_list');
    }

    /**
     * @Route("/local/load", name="manuel_translation_local_load")
     */
    public function loadFromFileAction()
    {
        $this->get('manuel_translation.local_synchronizator')->fromFile();

        return $this->redirectToRoute('manuel_translation_list');
    }

    /**
     * @Route("/local/write", name="manuel_translation_local_write")
     */
    public function writeToFileAction()
    {
        $this->get('manuel_translation.local_synchronizator')->toFile();

        return $this->redirectToRoute('manuel_translation_list');
    }
}