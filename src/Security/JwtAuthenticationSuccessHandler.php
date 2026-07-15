<?php

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler as BaseHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class JwtAuthenticationSuccessHandler extends BaseHandler
{
    /**
     * @param TokenInterface $token
     * @param Request $request
     * @return JsonResponse
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $response = parent::onAuthenticationSuccess($request, $token);

        // Get the data from the response
        $data = json_decode($response->getContent(), true);

        // Add user data
        $user = $token->getUser();
        if ($user instanceof User) {
            $data['user'] = $this->getUserData($user);
        }

        // Mutate the parent response in place instead of building a fresh one:
        // lexik attaches the BEARER auth cookie (Set-Cookie) to this response, and
        // returning a new JsonResponse would discard those headers/cookies.
        $response->setData($data);

        return $response;
    }

    private function getUserData(UserInterface $user): array
    {
        if (!$user instanceof User) {
            return [];
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
        ];
    }
}
