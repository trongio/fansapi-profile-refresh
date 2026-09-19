'use strict';

/*
 * The signing formula written out in JS. It mirrors
 * app/Refresh/Clients/OnlyfansSigner.php and is pinned to the same vectors
 * (contract/sign-vectors.json), so the two languages cannot drift apart.
 * Production signing happens in PHP; this exists for the cross-language test.
 */

const crypto = require('node:crypto');

function referenceSign(rules, path, time, userId = '0') {
    const sha = crypto
        .createHash('sha1')
        .update([rules.static_param, String(time), path, String(userId)].join('\n'), 'utf8')
        .digest('hex');

    let sum = rules.checksum_constant;
    for (const i of rules.checksum_indexes) {
        sum += sha.charCodeAt(i);
    }

    return `${rules.prefix}:${sha}:${Math.abs(sum).toString(16)}:${rules.suffix}`;
}

module.exports = { referenceSign };
