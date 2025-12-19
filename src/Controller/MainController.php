<?php

namespace App\Controller;

use App\Entity\Url;
use App\Repository\TokenRepository;
use App\Repository\UrlRepository;
use DateTime;
use Setono\BotDetectionBundle\BotDetector\BotDetectorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    public function __construct(
        protected readonly UrlRepository $urlRepository,
        protected readonly BotDetectorInterface $botDetector,
        protected readonly TokenRepository $tokenRepository,
    ) {

    }

    #[Route('/api', name: 'app_api', methods: ['POST'])]
    public function api(Request $request): Response
    {
        $data = $request->getPayload()->all();
        $customValid = false;

        if(
            !filter_var($data['url'], FILTER_VALIDATE_URL)
        ) {
            return $this->render('main/index.html.twig', [
                'invalid' => 'syntax'
            ]);
        }

        if(str_starts_with($data['url'], $request->getSchemeAndHttpHost())) {
            return $this->render('main/index.html.twig', [
                'invalid' => 'same'
            ]);
        }

        $url = new Url();
        $url
            ->setHash(bin2hex(random_bytes(4)))
            ->setUrl($data['url']);


        if(array_key_exists('isCustom', $data) && $data['isCustom'] === '1') {
            $customValid = true;
            $customToken = array_key_exists('customToken', $data) ? $this->tokenRepository->findOneByToken($data['customToken']) : null;


            if(!array_key_exists('customHash', $data)) {
                $customValid = false;
            }

            if(!array_key_exists('customToken', $data) || !$customToken) {
                $customValid = false;
            }

            if($customValid) {
                $oldUrl = $this->urlRepository->findOneByHash($data['customHash']);
                if($oldUrl !== null) {
                    $this->urlRepository->removeUrl($oldUrl);
                }
                $url
                    ->setHash($data['customHash'])
                    ->setToken($customToken);
            }
        }

        if(!$customValid) {
            if((!array_key_exists('isLimited', $data) || $data['isLimited'] !== '1')) {
                $data['isLimited'] = '1';
                $data['limit'] = '1024';
            }

            $limit = intval($data['limit']);

            if($limit > 1024) {
                $data['limit'] = '1024';
            }

            if((!array_key_exists('isRemaining', $data) || $data['isRemaining'] !== '1')) {
                $data['isRemaining'] = '1';
                $data['remainingUnit'] = 'days';
                $data['remainingTime'] = '7';
            }

            if($data['remainingUnit'] === 'days') {
                if(intval($data['limit']) > 7) {
                    $data['remainingTime'] = '7';
                }
            }
        }

        if(array_key_exists('isLimited', $data) && $data['isLimited'] === '1') {
            if(!array_key_exists('limit', $data)) {
                return $this->render('main/index.html.twig', [
                    'invalid' => 'syntax'
                ]);
            }

            $limit = intval($data['limit']);

            if(!$limit || $limit < 1 || $limit > 1024) {
                return $this->render('main/index.html.twig', [
                    'invalid' => 'syntax'
                ]);
            }


            $url->setRemainingClicks($limit);
        }

        if(array_key_exists('isRemaining', $data) && $data['isRemaining'] === '1') {
            if(!array_key_exists('remainingTime', $data) || !array_key_exists('remainingUnit', $data)) {
                return $this->render('main/index.html.twig', [
                    'invalid' => 'syntax'
                ]);
            }

            $validUnits = [
                'days', 'hours', 'minutes'
            ];

            $remainingTime = intval($data['remainingTime']);
            $remainingUnit = $data['remainingUnit'];

            if(!in_array($remainingUnit, $validUnits)) {
                return $this->render('main/index.html.twig', [
                    'invalid' => 'syntax'
                ]);
            }

            if(!$remainingTime || $remainingTime < 1 || $remainingTime > 31) {
                return $this->render('main/index.html.twig', [
                    'invalid' => 'syntax'
                ]);
            }

            $date = new DateTime();
            $date->modify("+{$remainingTime}{$data['remainingUnit']}");

            $url->setEndDate($date);
        }

        $this->urlRepository->updateUrl($url);

        return $this->render('main/index.html.twig', [
            'url' => $url,
        ]);
    }

    #[Route('/{hash}', name: 'app_main')]
    public function index(?string $hash = null): Response
    {
        $url = $this->urlRepository->findOneByHash($hash);

        if($url && !$this->botDetector->isBotRequest()) {
            if($url->getRemainingClicks() !== null) {
                $url->setRemainingClicks($url->getRemainingClicks() - 1);

                if($url->getRemainingClicks() === 0) {
                    $this->urlRepository->removeUrl($url);
                } else {
                    $this->urlRepository->updateUrl($url);
                }
            }

            return $this->redirect($url->getUrl());
        }

        if($hash) {
            return $this->render('main/index.html.twig', [
                'invalid' => 'missing'
            ]);
        }

        return $this->render('main/index.html.twig', []);
    }
}
