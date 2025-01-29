<?php

/**
 * @author Iulian Ceapa <dev@webspecto.com>
 * @copyright © 2023-2025 WebSpecto.
 */

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Collections\TagsCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\NoteType\CommonNote;
use AmoCRM\Models\TagModel;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Dotenv\Dotenv;

defined('ABSPATH') or die('Access denied');

class WP_Sync_AmoCRM_WPForms
{
    public function __construct($form_id, $fields)
    {
        $file_auth = WPSYNCAMO_DIR_PLUGIN . 'secret/auth.env';
        $forms_option = get_option('wpsyncamo_forms');

        if (
            !file_exists($file_auth) ||
            $forms_option === false ||
            !isset($forms_option['wpforms'][$form_id])
        ) {
            return false;
        }

        define('FILE_AUTH_TOKEN', WPSYNCAMO_DIR_PLUGIN . 'secret/auth_token.json');

        $dotenv = new Dotenv(false);
        $dotenv->load($file_auth);

        $api_client = new AmoCRMApiClient($_ENV['CLIENT_ID'], $_ENV['CLIENT_SECRET'], $_ENV['CLIENT_REDIRECT_URI']);
        $api_client->getOAuthClient()->setBaseDomain($_ENV['CLIENT_BASE_DOMAIN']);

        try {
            function getToken()
            {
                if (!file_exists(FILE_AUTH_TOKEN)) {
                    throw new \Exception('Authentication token is missing.');
                }

                $access_token = json_decode(file_get_contents(FILE_AUTH_TOKEN), true);

                return new AccessToken([
                    'access_token' => $access_token['accessToken'],
                    'refresh_token' => $access_token['refreshToken'],
                    'expires' => $access_token['expires'],
                    'baseDomain' => $access_token['baseDomain'],
                ]);
            }

            function saveToken($accessToken)
            {
                if (
                    isset($accessToken['accessToken'])
                    && isset($accessToken['refreshToken'])
                    && isset($accessToken['expires'])
                    && isset($accessToken['baseDomain'])
                ) {
                    $data = [
                        'accessToken' => $accessToken['accessToken'],
                        'refreshToken' => $accessToken['refreshToken'],
                        'expires' => $accessToken['expires'],
                        'baseDomain' => $accessToken['baseDomain'],
                    ];

                    file_put_contents(FILE_AUTH_TOKEN, json_encode($data));
                } else {
                    throw new \Exception('Invalid access token ' . var_export($accessToken, true));
                }
            }

            $access_token = getToken();
            $api_client->setAccessToken($access_token)
                ->setAccountBaseDomain($access_token->getValues()['baseDomain'])
                ->onAccessTokenRefresh(
                    function (AccessTokenInterface $accessToken, string $baseDomain) {
                        saveToken(
                            [
                                'accessToken' => $accessToken->getToken(),
                                'refreshToken' => $accessToken->getRefreshToken(),
                                'expires' => $accessToken->getExpires(),
                                'baseDomain' => $baseDomain,
                            ]
                        );
                    }
                );

            $contact = new ContactModel();

            $lead_name = isset($fields[$forms_option['wpforms'][$form_id]['name']]) ? esc_attr($fields[$forms_option['wpforms'][$form_id]['name']]) : '';
            $contact->setName($lead_name);

            $customFieldsValues = new CustomFieldsValuesCollection();

            $phone = new MultitextCustomFieldValuesModel();
            $phone->setFieldCode('PHONE');
            $lead_phone = isset($fields[$forms_option['wpforms'][$form_id]['phone']]) ? esc_attr($fields[$forms_option['wpforms'][$form_id]['phone']]) : null;
            $phone->setValues(
                (new MultitextCustomFieldValueCollection())
                    ->add(
                        (new MultitextCustomFieldValueModel())
                            ->setEnum('WORK')
                            ->setValue($lead_phone)
                    )
            );

            $email = new MultitextCustomFieldValuesModel();
            $email->setFieldCode('EMAIL');
            $lead_email = isset($fields[$forms_option['wpforms'][$form_id]['email']]) ? esc_attr($fields[$forms_option['wpforms'][$form_id]['email']]) : null;
            $email->setValues(
                (new MultitextCustomFieldValueCollection())
                    ->add(
                        (new MultitextCustomFieldValueModel())
                            ->setEnum('WORK')
                            ->setValue($lead_email)
                    )
            );

            $customFieldsValues->add($phone);
            $customFieldsValues->add($email);

            $contact->setCustomFieldsValues($customFieldsValues);

            $api_client->contacts()->addOne($contact);

            $lead = new LeadModel();
            $lead->setPipelineId(esc_attr($forms_option['wpforms'][$form_id]['pipeline']));
            $lead->setStatusId(esc_attr($forms_option['wpforms'][$form_id]['status']));
            $lead->setResponsibleUserId(esc_attr($forms_option['wpforms'][$form_id]['user_responsible']));
            $lead->setName($lead_name);

            $tags = new TagsCollection();
            foreach (explode(',', esc_attr($forms_option['wpforms'][$form_id]['tags'])) as $name) {
                $tag = new TagModel();
                $tag->setName($name);
                $tags->add($tag);
            }
            $lead->setTags($tags);

            $api_client->leads()->addOne($lead);

            $links = new LinksCollection();
            $links->add($contact);

            $api_client->leads()->link($lead, $links);

            $notes = new NotesCollection();

            $lead_text = isset($fields[$forms_option['wpforms'][$form_id]['text']]) ? esc_attr($fields[$forms_option['wpforms'][$form_id]['text']]) : ' ';

            $common_note = new CommonNote();
            $common_note->setEntityId($lead->getId())
                ->setText($lead_text)
                ->setCreatedBy(esc_attr($forms_option['wpforms'][$form_id]['user_responsible']));

            $notes->add($common_note);

            $notes_service = $api_client->notes(EntityTypesInterface::LEADS);
            $notes_service->add($notes);
        } catch (AmoCRMApiException $e) {
            trigger_error($e . PHP_EOL . json_encode($fields), E_USER_WARNING);
        } catch (\Exception $e) {
            trigger_error($e->getMessage() . PHP_EOL . json_encode($fields), E_USER_WARNING);
        }
    }
}
