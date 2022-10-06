<?php

namespace MarketplaceIntegration;

/**
 * Trait IntegrationUser
 * This trait used for working with client's data
 *
 * @package MarketplaceIntegration
 */
trait IntegrationUser
{
    /**
     * Method checks user existence in DB and returns it's id or tries to create new one.
     * If failed on both then use $defaultUserId
     *
     * @param string $name User's name
     * @param string $email User's email
     * @param string $phone User's phone (11 digits, 8 first: 89012223344)
     * @param int $customGroupId Add user to custom group (in addition to 3, 4 and 5 group)
     * @param int $defaultUserId If users not found or creation is failed this user id will be used
     *
     * @return int
     */
    public function getUserId(string $name, string $email, string $phone, int $customGroupId, int $defaultUserId): int
    {
        $arUser = \CUser::GetByLogin($email)->Fetch();

        if (isset($arUser['ID']) && 0 < intval($arUser['ID'])) {
            // Add user to necessary groups
            \CUser::SetUserGroup(
                $arUser['ID'],
                array_merge(\CUser::GetUserGroup($arUser['ID']), array(3, 4, 5, $customGroupId))
            );

            $userId = $arUser['ID'];
        } else {
            $user = new \CUser;
            $password = substr(md5(mt_rand()), 0, 7);
            $arFields = array(
                'NAME' => $name,
                'LAST_NAME' => '',
                'EMAIL' => $email,
                'LOGIN' => $email,
                'LID' => 'ru',
                'ACTIVE' => 'Y',
                'GROUP_ID' => array(3, 4, 5, $customGroupId),
                'PASSWORD' => $password,
                'CONFIRM_PASSWORD' => $password,
                // We don't store phone due to problems with registration. Users don't know that they already have HTM account
                // 'PERSONAL_PHONE' => 8 . substr($phone, 1)
            );

            $addedUserId = $user->Add($arFields);

            $userId = (0 < intval($addedUserId)) ? $addedUserId : $defaultUserId;
        }

        return intval($userId);
    }
}