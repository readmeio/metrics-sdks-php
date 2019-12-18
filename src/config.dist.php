<?php

use Illuminate\Http\Request;

return [
    // @todo extend the user of laravel queues

    /**
     * Your ReadMe API key. You can find this within your project configuration
     * on https://dash.readme.io/.
     */
    'api_key' => 'YOUR README API KEY',

    /**
     * This is a grouping callback that's run for every metric sent to ReadMe,
     * and is a way for you to group metrics against a specific user. This
     * function must return an array with at least an `id` key that represents
     * a unique identifier for the callee (session ID, user ID, etc.).
     *
     * Optionally, you may also return the following:
     *
     *  - `label`: This will be used to identify the user on ReadMe, since it's
     *      much easier to remember a name than a unique identifier.
     *  - `email`: Email of the person that is making the call.
     *
     * @link https://docs.readme.com/docs/sending-api-logs-to-readme
     */
    'group' => function (Request $request): array {
        $user = $request->user();
        if (!$user) {
            return [
                'id' => session()->getId()
            ];
        }

        return [
            'id' => $user->id,
            'label' => $user->name,
            'email' => $user->email
        ];
    },

    /**
     * Since ReadMe doesn't want to take your API down if you happen to be unable
     * to send metrics to us, development mode is enabled by default and will
     * silence all possible errors in transit.
     *
     * While you are still setting up and testing your integration, we recommend
     * enabling this option so you can see any possible error that may be
     * present in your payload before you deploy to production.
     */
    'development_mode' => true,

    /**
     * An array of dot-notation values from your API requests and responses that
     * you wish to blacklist from sending to the metrics service. If this option
     * has data in it, the whitelist below will be ignored.
     */
    'blacklist' => [],

    /**
     * An array of dot-notation values from your API requests and responses that
     * you only wish to send to the metrics service.
     */
    'whitelist' => [],
];
