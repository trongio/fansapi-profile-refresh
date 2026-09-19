<?php

namespace App\Refresh\Clients;

/**
 * Two gates a candidate rule set must pass before it is activated.
 *
 *  - Proof: rulegen ran the REAL extracted sign function on fixed inputs (the
 *    shared contract file). PHP recomputes each sign with OnlyfansSigner from
 *    the derived constants; any difference means the extraction or the PHP
 *    signer is wrong for this build. This proves compatibility, not that the
 *    rulegen process is honest.
 *  - Canary (optional): one live signed request. Only OnlyFans accepting the
 *    signature proves the rules are current.
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
                || ! is_string($vector['sign'] ?? null)) {
                throw new RulegenFailed('PROOF_MISMATCH', "proof vector {$i} does not match the contract inputs");
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
        $username = (string) config('fansapi.onlyfans.canary_username');
        $result = $this->client->request($rules, '/api2/v2/users/'.rawurlencode($username));

        if (! $result->ok() || ! isset($result->body['id'])) {
            throw new RulegenFailed('CANARY_FAILED', sprintf(
                'canary %s: %s %s',
                $username,
                $result->category,
                $result->status ?? '-',
            ));
        }
    }

    /** @return list<array{path: string, time: string}> */
    public static function proofInputs(): array
    {
        $inputs = json_decode((string) file_get_contents(base_path('services/rulegen/contract/proof-inputs.json')), true, 4, JSON_THROW_ON_ERROR);

        return array_map(fn (array $i): array => ['path' => (string) $i['path'], 'time' => (string) $i['time']], $inputs);
    }
}
