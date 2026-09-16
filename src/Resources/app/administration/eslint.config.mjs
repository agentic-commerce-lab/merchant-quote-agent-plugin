// Committed config for MerchantQuoteAgentPlugin. Composes the generated Shopware bridge in .shopware/
// (git-ignored). Safe to edit and commit — keep the import and the ...spread.
import shopware from './.shopware/eslint.mjs';

export default [
    ...shopware,
    // Add your own rules here.
];
