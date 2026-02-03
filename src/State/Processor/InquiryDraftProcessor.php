<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Inquiry;
use App\Repository\InquiryRepository;
use App\Service\InquiryLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Processor for managing draft inquiries (saving and submitting)
 */
class InquiryDraftProcessor implements ProcessorInterface
{
    private InquiryRepository $inquiryRepository;
    private Security $security;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private InquiryLogService $inquiryLogService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security               $security,
        InquiryLogService      $inquiryLogService,
        LoggerInterface        $logger = null
    )
    {
        $this->inquiryRepository = $entityManager->getRepository(Inquiry::class);
        $this->security = $security;
        $this->entityManager = $entityManager;
        $this->inquiryLogService = $inquiryLogService;
        $this->logger = $logger;
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Inquiry
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new AccessDeniedHttpException('You must be logged in to manage draft inquiries');
        }

        // Extract inquiry ID from URI variables
        $inquiryId = $uriVariables['id'] ?? null;

        if (!$inquiryId) {
            throw new NotFoundHttpException('Inquiry ID is required');
        }

        // Find the inquiry
        $inquiry = $this->inquiryRepository->find($inquiryId);

        if (!$inquiry) {
            throw new NotFoundHttpException('Inquiry not found');
        }

        // Check if the inquiry belongs to the current user
        if ($inquiry->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException('You do not have permission to modify this inquiry');
        }

        // Determine the operation (save-draft or submit)
        $path = $operation->getUriTemplate();

        if (str_contains($path, '/save-draft')) {
            // Save as draft
            $inquiry->saveDraft();
            $this->entityManager->flush();

            if ($this->logger) {
                $this->logger->info('Inquiry saved as draft', [
                    'inquiry_id' => $inquiry->getId()->toRfc4122(),
                    'inquiry_number' => $inquiry->getInquiryNumber()
                ]);
            }
        } elseif (str_contains($path, '/submit')) {
            // Check if the inquiry can be submitted before processing
            if (!$inquiry->canBeSubmitted()) {
                throw new \InvalidArgumentException(
                    'Inquiry cannot be submitted. ' . implode(', ', $inquiry->getSubmissionErrors())
                );
            }

            // Submit the draft inquiry
            $inquiry->submitInquiry();
            $this->entityManager->flush();

            // Log the submission (draft → submitted)
            $log = $this->inquiryLogService->logInquirySubmission(
                $inquiry,
                'Inquiry submitted from draft by ' . ($user->getFullName() ?? $user->getEmail())
            );

            if ($log !== null) {
                $this->entityManager->flush();

                if ($this->logger) {
                    $this->logger->info('Inquiry submission logged', [
                        'inquiry_id' => $inquiry->getId()->toRfc4122(),
                        'log_id' => $log->getId()->toRfc4122()
                    ]);
                }
            }

            if ($this->logger) {
                $this->logger->info('Draft inquiry submitted', [
                    'inquiry_id' => $inquiry->getId()->toRfc4122(),
                    'inquiry_number' => $inquiry->getInquiryNumber(),
                    'new_status' => $inquiry->getStatus()
                ]);
            }
        }

        return $inquiry;
    }
}
