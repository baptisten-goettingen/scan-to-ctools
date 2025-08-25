<?php

namespace App\Services;

use CTApi\CTClient;
use CTApi\CTConfig;

class ChurchToolsService
{
    private string $apiUrl;
    private string $username;
    private string $password;
    private string $wikiDomain;
    private string $wikiDomainIdentifier;

    public function __construct(string $apiUrl, string $username, string $password, string $wikiDomain, string $wikiDomainIdentifier)
    {
        $this->apiUrl = $apiUrl;
        $this->username = $username;
        $this->password = $password;
        $this->wikiDomain = $wikiDomain;
        $this->wikiDomainIdentifier = $wikiDomainIdentifier;

        $this->configureClient();
    }

    /**
     * Konfiguriert den ChurchTools API Client.
     */
    private function configureClient(): void
    {
        CTConfig::setApiUrl($this->apiUrl);
        CTConfig::authWithCredentials($this->username, $this->password);
    }

    /**
     * Holt den CSRF-Token von der ChurchTools API.
     *
     * @return string|null
     */
    public function getCsrfToken(): ?string
    {
        try {
            $response = CTClient::getClient()->get("/api/csrftoken");
            $csrfToken = json_decode($response->getBody()->getContents())->data;
            return $csrfToken;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Führt den Datei-Upload per CURL aus.
     *
     * @param string $filepath
     * @param string $csrfToken
     * @return string
     */
    public function uploadFile(string $filepath, string $csrfToken): string
    {
        $apiUrl = sprintf(
            "%s/api/files/%s/%s",
            $this->apiUrl,
            $this->wikiDomain,
            $this->wikiDomainIdentifier
        );

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "content-type:multipart/form-data",
            "csrf-token:" . $csrfToken
        ]);

        // Set Cookie
        $cookie = CTConfig::getSessionCookie();
        if ($cookie != null) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie["Name"] . '=' . $cookie["Value"]);
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ["files[]" => curl_file_create($filepath)]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resultString = (string)curl_exec($ch);
        curl_close($ch);

        return $resultString;
    }
}