<?php
/**
 * /classes/DomainMOD/Infomaniak.php
 *
 * This file is part of DomainMOD, an open source domain and internet asset manager.
 * Copyright (c) 2010-2025 Greg Chetcuti <greg@greg.ca>
 *
 * Project: http://domainmod.org   Author: https://greg.ca
 *
 * DomainMOD is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * DomainMOD is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with DomainMOD. If not, see
 * http://www.gnu.org/licenses/.
 *
 */
//@formatter:off
namespace DomainMOD;


class Infomaniak
{
    public $deeb;
    public $format;
    public $log;
    private $domain_cache = array();


    public function __construct()
    {
        $this->format = new Format();
        $this->log = new Log('class.infomaniak');
        $this->deeb = Database::getInstance();
    }


    public function getApiUrl($command, $domain = '', $page = 1)
    {
        if ($command == 'domainlist') {
            return 'https://api.infomaniak.com/2/domains/domains?page=' . $page;
        } elseif ($command == 'info') {
            return 'https://api.infomaniak.com/2/domains/domains/' . urlencode($domain);
        } elseif ($command == 'nameservers') {
            return 'https://api.infomaniak.com/2/zones/' . urlencode($domain);
        } else {
            return array(_('Unable to build API URL'), '');
        }
    }


    public function apiCall($full_url, $api_key)
    {
        $handle = curl_init($full_url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($handle);
        curl_close($handle);
        return $result;
    }


    public function getDomainList($account_username, $account_id, $api_key)
    {
        $domain_list = array();
        $domain_count = 0;
        $page = 1;
        $total_pages = 1;

        do {

            $api_url = $this->getApiUrl('domainlist', '', $page);
            $api_results = $this->apiCall($api_url, $api_key);
            $array_results = $this->convertToArray($api_results);

            if (isset($array_results['result']) && $array_results['result'] === 'success'
                && isset($array_results['data']) && is_array($array_results['data'])) {

                foreach ($array_results['data'] as $domain) {

                    $name = isset($domain['name']) ? $domain['name'] : '';
                    if (!empty($name)) {
                        $domain_list[] = $name;
                        $domain_count++;
                        // Cacha tutti i campi già disponibili dalla lista
                        $this->domain_cache[$name] = $domain;
                    }

                }

                $total_pages = isset($array_results['pages']) ? (int) $array_results['pages'] : 1;
                $page++;

            } else {

                $log_message = 'Unable to get domain list';
                $log_extra = array('API Key' => $this->format->obfusc($api_key), 'Page' => $page);
                $this->log->error($log_message, $log_extra);
                break;

            }

        } while ($page <= $total_pages);

        return array($domain_count, $domain_list);
    }


    public function getFullInfo($account_username, $account_id, $api_key, $domain)
    {
        $expiration_date = '';
        $dns_servers = array();
        $privacy_status = '';
        $autorenewal_status = '';

        // --- Dati dominio: usa cache se disponibile, altrimenti chiama API ---
        if (isset($this->domain_cache[$domain])) {

            $result = $this->domain_cache[$domain];

        } else {

            $api_url = $this->getApiUrl('info', $domain);
            $api_results = $this->apiCall($api_url, $api_key);
            $array_results = $this->convertToArray($api_results);

            if (isset($array_results['result']) && $array_results['result'] === 'success'
                && isset($array_results['data'])) {
                $result = $array_results['data'];
            } else {
                $log_message = 'Unable to get domain details';
                $log_extra = array('Domain' => $domain, 'API Key' => $this->format->obfusc($api_key));
                $this->log->error($log_message, $log_extra);
                return array($expiration_date, $dns_servers, $privacy_status, $autorenewal_status);
            }

        }

        // expires_at è Unix timestamp
        if (!empty($result['expires_at'])) {
            $expiration_date = date('Y-m-d', (int) $result['expires_at']);
        }

        // privacy in options.domain_privacy — stringa 'true'/'false'
        $privacy_raw = isset($result['options']['domain_privacy'])
            ? (string) $result['options']['domain_privacy']
            : 'false';
        $privacy_status = $this->processPrivacy($privacy_raw);

        // auto_renew — tenta più campi possibili
        $autorenewal_raw = 'false';
        foreach (array('auto_renew', 'renewal_auto', 'autorenew') as $field) {
            if (isset($result[$field])) {
                $autorenewal_raw = (string) $result[$field];
                break;
            }
        }
        if ($autorenewal_raw === 'false' && isset($result['options']['auto_renew'])) {
            $autorenewal_raw = (string) $result['options']['auto_renew'];
        }
        $autorenewal_status = $this->processAutorenew($autorenewal_raw);

        // --- Nameservers: GET /2/zones/{domain} ---
        $ns_url = $this->getApiUrl('nameservers', $domain);
        $ns_results = $this->apiCall($ns_url, $api_key);
        $ns_array = $this->convertToArray($ns_results);

        if (isset($ns_array['result']) && $ns_array['result'] === 'success'
            && isset($ns_array['data']['nameservers']) && is_array($ns_array['data']['nameservers'])) {

            $dns_servers = $this->processDns($ns_array['data']['nameservers']);

        } else {

            // Fallback: nameservers non disponibili, usa placeholder
            $log_message = 'Unable to get nameservers, using placeholder';
            $log_extra = array('Domain' => $domain);
            $this->log->warning($log_message, $log_extra);
            $dns_servers = $this->processDns(array());

        }

        return array($expiration_date, $dns_servers, $privacy_status, $autorenewal_status);
    }


    public function convertToArray($api_result)
    {
        return json_decode($api_result, true);
    }


    public function processDns($dns_result)
    {
        $dns_servers = array();
        if (!empty($dns_result)) {
            $dns_servers = array_filter($dns_result);
        } else {
            $dns_servers[0] = 'no.dns-servers.1';
            $dns_servers[1] = 'no.dns-servers.2';
        }
        return $dns_servers;
    }


    public function processPrivacy($privacy_result)
    {
        if ($privacy_result === 'true' || $privacy_result === '1' || $privacy_result === true) {
            $privacy_status = '1';
        } else {
            $privacy_status = '0';
        }
        return $privacy_status;
    }


    public function processAutorenew($autorenewal_result)
    {
        if ($autorenewal_result === 'true' || $autorenewal_result === '1' || $autorenewal_result === true) {
            $autorenewal_status = '1';
        } else {
            $autorenewal_status = '0';
        }
        return $autorenewal_status;
    }


} //@formatter:on
