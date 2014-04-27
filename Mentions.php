<?php
/**
 * Main mentions file for @mentions mod for SMF
 *
 * @author Shitiz Garg <mail@dragooon.net>
 * @copyright 2014 Shitiz Garg
 * @license Simplified BSD (2-Clause) License
 */

/**
 * Callback for integrate_bbc_codes
 *
 * @param array &$bbc_tags
 * @return void
 */
function mentions_bbc(array &$bbc_tags)
{
    global $scripturl;

    $bbc_tags[] = array(
        'tag' => 'member',
        'type' => 'unparsed_equals',
        'before' => '<a href="' . $scripturl . '?action=profile;u=$1" class="mention">@',
        'after' => '</a>',
    );
}

/**
 * Callback for integrate_emnu_buttons
 *
 * @param array &$menu_buttons
 * @return void
 */
function mentions_menu(array &$menu_buttons)
{
    global $txt, $scripturl, $smcFunc, $user_info;

    loadLanguage('Mentions');

    $request = $smcFunc['db_query']('', '
        SELECT COUNT(*)
        FROM {db_prefix}log_mentions
        WHERE id_mentioned = {int:member}
          AND unseen = 1',
        array(
            'member' => $user_info['id'],
        )
    );
    list ($user_info['unseen_mentions']) = $smcFunc['db_fetch_row']($request);
    $smcFunc['db_free_result']($request);

    $menu_buttons['profile']['sub_buttons']['mentions'] = array(
        'title' => $txt['mentions'] . (!empty($user_info['unseen_mentions']) ? ' [' . $user_info['unseen_mentions'] . ']' : ''),
        'href' => $scripturl . '?action=profile;area=mentions',
        'show' => true,
    );
    $menu_buttons['profile']['title'] .=  (!empty($user_info['unseen_mentions']) ? ' [' . $user_info['unseen_mentions'] . ']' : '');
}

/**
 * Hook callback for integrate_profile_areas
 *
 * @param array $profile_areas
 * @return void
 */
function mentions_profile_areas(array &$profile_areas)
{
    global $txt;

    loadLanguage('Mentions');

    $profile_areas['info']['areas']['mentions'] = array(
        'label' => $txt['mentions'],
        'enabled' => true,
        'file' => 'Mentions.php',
        'function' => 'Mentions_Profile',
        'permission' => array(
            'own' => 'profile_view_own',
            'any' => 'profile_identity_any',
        ),
    );
}

/**
 * Hook callback for integrate_load_permissions
 *
 * @param array &$permissionGroups
 * @param array &$permissionList
 * @param array &$leftPermissionGroups
 * @param array &$hiddenPermissions
 * @param array &$relabelPermissions
 * @return void
 */
function mentions_permissions(array &$permissionGroups, array &$permissionList, array &$leftPermissionGroups, array &$hiddenPermissions, array &$relabelPermissions)
{
    loadLanguage('Mentions');

    $permissionList['membergroup']['mention_member'] = array(false, 'general', 'view_basic_info');
}

/**
 * Parses a post, actually looks for mentions and stores then in $msgOptions
 * We can't actually store them here if we don't have the ID of the post
 *
 * Names are tagged by "@<username>" format in post, but they can contain
 * any type of character up to 60 characters length. So we extract, starting from @
 * up to 60 characters in length (or if we encounter another @ or a line break) and make
 * several combination of strings after splitting it by anything that's not a word and join
 * by having the first word, first and second word, first, second and third word and so on and
 * search every name.
 *
 * One potential problem with this is something like "@Admin Space" can match
 * "Admin Space" as well as "Admin", so we sort by length in descending order.
 * One disadvantage of this is that we can only match by one column, hence I've chosen
 * real_name since it's the most obvious.
 *
 * Names having "@" in there names are expected to be escaped as "\@",
 * otherwise it'll break seven ways from sunday
 *
 * @param array &$msgOptions
 * @param array &$topicOptions
 * @param array &$posterOptions
 * @return void
 */
function mentions_process_post(&$msgOptions, &$topicOptions, &$posterOptions)
{
    global $smcFunc, $user_info;

    // Undo some of the preparse code action
    $body = preg_replace('~<br\s*/?\>~', "\n", str_replace('&nbsp;', ' ', $msgOptions['body']));

    // Attempt to match all the @<username> type mentions in the post
    preg_match_all('/@(([^@\n\\\\]|\\\@){1,60})/', strip_tags($body), $matches);

    // Names can have spaces, or they can't...we try to match every possible
    if (empty($matches[1]) || !allowedTo('mention_member'))
        return;

    // Names can have spaces, other breaks, or they can't...we try to match every possible
    // combination.
    $names = array();
    foreach ($matches[1] as $match)
    {
        $match = preg_split('/([^\w])/', $match, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i <= count($match); $i++)
            $names[] = str_replace('\@', '@', implode('', array_slice($match, 0, $i)));
    }

    $names = array_unique(array_map('trim', $names));

    // Attempt to fetch all the valid usernames along with their required metadata
    $request = $smcFunc['db_query']('', '
        SELECT id_member, real_name, email_mentions, email_address
        FROM {db_prefix}members
        WHERE real_name IN ({array_string:names})
        ORDER BY LENGTH(real_name) DESC
        LIMIT {int:count}',
        array(
            'names' => $names,
            'count' => count($names),
        )
    );
    $members = array();
    while ($row = $smcFunc['db_fetch_assoc']($request))
        $members[$row['id_member']] = array(
            'id' => $row['id_member'],
            'real_name' => str_replace('@', '\@', $row['real_name']),
            'original_name' => $row['real_name'],
            'email_mentions' => $row['email_mentions'],
            'email_address' => $row['email_address']
        );
    $smcFunc['db_free_result']($request);
    if (empty($members))
        return;

    // Replace all the tags with BBCode ([member=<id>]<username>[/member])
    $msgOptions['mentions'] = array();
    foreach ($members as $member)
    {
        if (strpos($msgOptions['body'], '@' . $member['real_name']) === false)
            continue;

        $msgOptions['body'] = str_replace('@' . $member['real_name'], '[member=' . $member['id'] . ']' . $member['original_name'] . '[/member]', $msgOptions['body']);

        // Why would an idiot mention themselves?
        if ($user_info['id'] == $member['id'])
            continue;

        $msgOptions['mentions'][] = $member;
    }
}

/**
 * Takes mention_process_post's arrays and calls mention_store
 *
 * @param array $mentions
 * @param int $id_post
 * @param string $subject
 * @return void
 */
function mentions_process_store(array $mentions, $id_post, $subject)
{
    global $smcFunc, $txt, $user_info, $scripturl;

    foreach ($mentions as $mention)
    {
        // Store this quickly
        $smcFunc['db_insert']('replace',
            '{db_prefix}log_mentions',
            array('id_post' => 'int', 'id_member' => 'int', 'id_mentioned' => 'int', 'time' => 'int'),
            array($id_post, $user_info['id'], $mention['id'], time()),
            array('id_post', 'id_member', 'id_mentioned')
        );

        if (!empty($mention['email_mentions']))
        {
            $replacements = array(
                'POSTNAME' => $subject,
                'MENTIONNAME' => $mention['original_name'],
                'MEMBERNAME' => $user_info['name'],
                'POSTLINK' => $scripturl . '?post=' . $id_post,
            );

            loadLanguage('Mentions');

            $subject = str_replace(array_keys($replacements), array_values($replacements), $txt['mentions_subject']);
            $body = str_replace(array_keys($replacements), array_values($replacements), $txt['mentions_body']);
            sendmail($mention['email_address'], $subject, $body);
        }
    }
}

/**
 * Handles the profile area for mentions
 *
 * @param int $memID
 * @return void
 */
function Mentions_Profile($memID)
{
    global $smcFunc, $sourcedir, $txt, $context, $modSettings, $user_info, $scripturl;

    loadLanguage('Mentions');

    if (!empty($_POST['save']))
        updateMemberData($memID, array('email_mentions' => (bool) !empty($_POST['email_mentions'])));

    $smcFunc['db_query']('', '
        UPDATE {db_prefix}log_mentions
        SET unseen = 0
        WHERE id_mentioned = {int:member}',
        array(
            'member' => $user_info['id'],
        )
    );

    $request = $smcFunc['db_query']('', '
        SELECT emaiL_mentions
        FROM {db_prefix}members
        WHERE id_member = {int:member}',
        array(
            'member' => $memID,
        )
    );
    list ($email_mentions) = $smcFunc['db_fetch_row']($request);
    $smcFunc['db_free_result'];

    // Set the options for the list component.
    $listOptions = array(
        'id' => 'mentions_list',
        'title' => substr($txt['mentions_profile_title'], $user_info['name']),
        'items_per_page' => 20,
        'base_href' => $scripturl . '?action=profile;area=tracking;sa=user;u=' . $memID,
        'default_sort_col' => 'time',
        'get_items' => array(
            'function' => 'list_getMentions',
            'params' => array(
                'lm.id_mentioned = {int:current_member}',
                array('current_member' => $memID),
            ),
        ),
        'get_count' => array(
            'function' => 'list_getMentionsCount',
            'params' => array(
                'lm.id_mentioned = {int:current_member}',
                array('current_member' => $memID),
            ),
        ),
        'columns' => array(
            'subject' => array(
                'header' => array(
                    'value' => $txt['mentions_post_subject'],
                ),
                'data' => array(
                    'sprintf' => array(
                        'format' => '<a href="' . $scripturl . '?post=%d">%s</a>',
                        'params' => array(
                            'id_post' => false,
                            'subject' => false,
                        ),
                    ),
                ),
                'sort' => array(
                    'default' => 'msg.subject DESC',
                    'reverse' => 'msg.subject ASC',
                ),
            ),
            'by' => array(
                'header' => array(
                    'value' => $txt['mentions_member'],
                ),
                'data' => array(
                    'sprintf' => array(
                        'format' => '<a href="' . $scripturl . '?action=profile;u=%d">%s</a>',
                        'params' => array(
                            'id_member' => false,
                            'real_name' => false,
                        ),
                    ),
                ),
            ),
            'time' => array(
                'header' => array(
                    'value' => $txt['mentions_post_time'],
                ),
                'data' => array(
                    'db' => 'time',
                ),
                'sort' => array(
                    'default' => 'lm.time DESC',
                    'reverse' => 'lm.time ASC',
                ),
            ),
        ),
        'form' => array(
            'href' => $scripturl . '?action=profile;area=mentions',
            'include_sort' => true,
            'include_start' => true,
            'hidden_fields' => array(
                'save' => true,
            ),
        ),
        'additional_rows' => array(
            array(
                'position' => 'bottom_of_list',
                'value' => '<label for="email_mentions">' . $txt['email_mentions'] . ':</label> <input type="checkbox" name="email_mentions" value="1" onchange="this.form.submit()"' . ($email_mentions ? ' checked' : '') . ' />',
            ),
        ),
    );

    // Create the list for viewing.
    require_once($sourcedir . '/Subs-List.php');
    createList($listOptions);

    $context['default_list'] = 'mentions_list';
    $context['sub_template'] = 'show_list';
}

function list_getMentionsCount($where, $where_vars = array())
{
    global $smcFunc;

    $request = $smcFunc['db_query']('', '
		SELECT COUNT(lm.id_mentioned) AS mentions_count
		FROM {db_prefix}log_mentions AS lm
		WHERE ' . $where,
        $where_vars
    );
    list ($count) = $smcFunc['db_fetch_row']($request);
    $smcFunc['db_free_result']($request);

    return $count;
}

function list_getMentions($start, $items_per_page, $sort, $where, $where_vars = array())
{
    global $smcFunc, $txt, $scripturl;

    // Get a list of error messages from this ip (range).
    $request = $smcFunc['db_query']('', '
		SELECT
			lm.id_post, lm.id_mentioned, lm.id_member, lm.time,
			mem.real_name, msg.subject
        FROM {db_prefix}log_mentions AS lm
            INNER JOIN {db_prefix}members AS mem ON (mem.id_member = lm.id_member)
            INNER JOIN {db_prefix}messages AS msg ON (msg.id_msg = lm.id_post)
            INNER JOIN {db_prefix}topics AS t ON (t.id_topic = msg.id_topic)
            INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
		WHERE ' . $where . '
		    AND {query_see_board}
		ORDER BY ' . $sort . '
		LIMIT ' . $start . ', ' . $items_per_page,
        $where_vars
    );
    $mentions = array();
    while ($row = $smcFunc['db_fetch_assoc']($request))
    {
        $row['time'] = timeformat($row['time']);
        $mentions[] = $row;
    }
    $smcFunc['db_free_result']($request);

    return $mentions;
}