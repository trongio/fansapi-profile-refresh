<?php

namespace App\Refresh\Clients;

use App\Refresh\Outcome;

/**
 * The gates a candidate signer must pass before it is activated, and the
 * recurring live check of the active one.
 *
 *  - Proof (constants mode): rulegen ran the REAL extracted sign function on
 *    fixed inputs (the shared contract file). PHP recomputes each sign with
 *    OnlyfansSigner from the derived constants; any difference means the
 *    extraction or the PHP signer is wrong for this build. This proves
 *    compatibility, not that the rulegen process is honest.
 *  - Proof (delegated mode): the formula changed, so PHP cannot recompute;
 *    it checks the vectors cover exactly the contract inputs. The canary is
 *    then mandatory.
 *  - Canary: one live signed request for a known public profile, which must
 *    return 200 and that profile's id. Only OnlyFans accepting the signature
 *    proves the signer is current.
 */
class OnlyfansRuleVerifier
{
    public function __construct(private readonly OnlyfansDirectClient $client) {}

    /** @throws RulegenFailed */
    public function verifyProof(OnlyfansRuleSet $rules, mixed $proof): void
    {
        $inputs = self::proofInputs();

        if (! is_array($proof) || ! array_is_list($proof) || count($proof) !== count($inputs)) {
            throw new RulegenFailed('PROOF_MISMATCH', 'proof vectors missing or of the wrong length');
        }

        foreach ($inputs as $i => $input) {
            $vector = $proof[$i];
            if (! is_array($vector)
                || ($vector['path'] ?? null) !== $input['path']
                || ($vector['time'] ?? null) !== $input['time']
                || ($vector['user_id'] ?? null) !== '0'
                || ! is_string($vector['sign'] ?? null)
                || preg_match('/\A[\x21-\x7e]{1,256}\z/', $vector['sign']) !== 1) {
                throw new RulegenFailed('PROOF_MISMATCH', "proof vector {$i} does not match the contract inputs");
            }

            if ($rules->delegated()) {
                continue;
            }

            $expected = OnlyfansSigner::headers($rules->signatureRules(), $input['path'], '0', (int) $input['time'])['sign'];
            if (! hash_equals($expected, $vector['sign'])) {
                throw new RulegenFailed('PROOF_MISMATCH', "PHP signer disagrees with the extracted function on vector {$i}");
            }
        }
    }

    /** @throws RulegenFailed */
    public function canary(OnlyfansRuleSet $rules): void
    {
        $result = $this->probe($rules);

        if (! $this->accepted($result)) {
            throw new RulegenFailed('CANARY_FAILED', sprintf(
                'canary %s: %s %s',
                config('fansapi.onlyfans.canary_username'),
                $result->category,
                $result->status ?? '-',
            ));
        }
    }

    /** One signed request for the canary profile, whatever the outcome. */
    public function probe(OnlyfansRuleSet $rules): ClientResult
    {
        return $this->client->request($rules, '/api2/v2/users/'.rawurlencode((string) config('fansapi.onlyfans.canary_username')));
    }

    /** 200 and, when configured, the canary profile's own upstream id. */
    public function accepted(ClientResult $result): bool
    {
        if (! $result->ok() || ! isset($result->body['id'])) {
            return false;
        }

        $expectedId = (string) config('fansapi.onlyfans.canary_upstream_id');

        return $expectedId === '' || (string) $result->body['id'] === $expectedId;
    }

    public static function rejected(ClientResult $result): bool
    {
        return $result->category === Outcome::SIGNATURE_REJECTED;
    }

    /** @return list<array{path: string, time: string}> */
    public static function proofInputs(): array
    {
        $inputs = json_decode((string) file_get_contents(base_path('services/rulegen/contract/proof-inputs.json')), true, 4, JSON_THROW_ON_ERROR);

        return array_map(fn (array $i): array => ['path' => (string) $i['path'], 'time' => (string) $i['time']], $inputs);
    }
}
