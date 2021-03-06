<?php
require_once 'libs/dao/QualityNominations.dao.php';
require_once 'libs/dao/QualityNomination_Reviewers.dao.php';

class QualityNominationController extends Controller {
    /**
     * Number of reviewers to automatically assign each nomination.
     */
    const REVIEWERS_PER_NOMINATION = 2;

    /**
     * Creates a new QualityNomination
     *
     * There are two ways in which users can interact with this:
     *
     * # Promotion
     *
     * A user that has already solved a problem can nominate it to be promoted
     * as a Quality Problem. This expects the `nomination` field to be
     * `promotion` and the `contents` field should be a JSON blob with the
     * following fields:
     *
     * * `rationale`: A small text explaining the rationale for promotion.
     * * `statements`: A dictionary of languages to objects that contain a
     *                 `markdown` field, which is the markdown-formatted
     *                 problem statement for that language.
     * * `source`: A URL or string clearly documenting the source or full name
     *             of original author of the problem.
     * * `tags`: An array of tag names that will be added to the problem upon
     *           promotion.
     *
     * # Demotion
     *
     * A demoted problem is banned, and cannot be un-banned or added to any new
     * problemsets. This expects the `nomination` field to be `demotion` and
     * the `contents` field should be a JSON blob with the following fields:
     *
     * * `rationale`: A small text explaining the rationale for demotion.
     * * `reason`: One of `['duplicate', 'offensive']`.
     * * `original`: If the `reason` is `duplicate`, the alias of the original
     *               problem.
     *
     * @param Request $r
     *
     * @return array
     * @throws DuplicatedEntryInDatabaseException
     * @throws InvalidDatabaseOperationException
     */
    public static function apiCreate(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Validate request
        self::authenticateRequest($r);

        Validators::isStringNonEmpty($r['problem_alias'], 'problem_alias');
        Validators::isInEnum($r['nomination'], 'nomination', ['promotion', 'demotion']);
        Validators::isStringNonEmpty($r['contents'], 'contents');

        $contents = json_decode($r['contents'], true /*assoc*/);
        if (!is_array($contents)
            || (!isset($contents['rationale']) || !is_string($contents['rationale']) || empty($contents['rationale']))
        ) {
            throw new InvalidParameterException('parameterInvalid', 'contents');
        }

        $problem = ProblemsDAO::getByAlias($r['problem_alias']);
        if (is_null($problem)) {
            throw new NotFoundException('problemNotFound');
        }

        if ($r['nomination'] == 'promotion') {
            // When a problem is being nominated for promotion, the user
            // nominating it must have already solved it.
            if (!ProblemsDAO::isProblemSolved($problem, $r['current_user'])) {
                throw new PreconditionFailedException('qualityNominationMustHaveSolvedProblem');
            }
            if ((!isset($contents['statements']) || !is_array($contents['statements']))
                || (!isset($contents['source']) || !is_string($contents['source']) || empty($contents['source']))
                || (!isset($contents['tags']) || !is_array($contents['tags']))
            ) {
                throw new InvalidParameterException('parameterInvalid', 'contents');
            }
            // Tags must be strings.
            foreach ($contents['tags'] as &$tag) {
                if (!is_string($tag)) {
                    throw new InvalidParameterException('parameterInvalid', 'contents');
                }
                $tag = TagController::normalize($tag);
            }
            // Statements must be a dictionary of language => { 'markdown': string }.
            foreach ($contents['statements'] as $language => $statement) {
                if (!is_array($statement) || empty($language)
                    || (!isset($statement['markdown']) || !is_string($statement['markdown']) || empty($statement['markdown']))
                ) {
                    throw new InvalidParameterException('parameterInvalid', 'contents');
                }
            }
        } elseif ($r['nomination'] == 'demotion') {
            if (!isset($contents['reason']) || !in_array($contents['reason'], ['duplicate', 'offensive'])) {
                throw new InvalidParameterException('parameterInvalid', 'contents');
            }
            // Duplicate reports need more validation.
            if ($contents['reason'] == 'duplicate') {
                if (!isset($contents['original']) || !is_string($contents['original']) || empty($contents['original'])) {
                    throw new InvalidParameterException('parameterInvalid', 'contents');
                }
                $original = ProblemsDAO::getByAlias($contents['original']);
                if (is_null($original)) {
                    throw new NotFoundException('problemNotFound');
                }
            }
        }

        // Create object
        $nomination = new QualityNominations([
            'user_id' => $r['current_user_id'],
            'problem_id' => $problem->problem_id,
            'nomination' => $r['nomination'],
            'contents' => json_encode($contents), // re-encoding it for normalization.
            'status' => 'open',
        ]);
        QualityNominationsDAO::save($nomination);

        $qualityReviewerGroup = GroupsDAO::FindByAlias(
            Authorization::QUALITY_REVIEWER_GROUP_ALIAS
        );
        foreach (GroupsDAO::sampleMembers(
            $qualityReviewerGroup,
            self::REVIEWERS_PER_NOMINATION
        ) as $reviewer) {
            QualityNominationReviewersDAO::save(new QualityNominationReviewers([
                'qualitynomination_id' => $nomination->qualitynomination_id,
                'user_id' => $reviewer->user_id,
            ]));
        }

        return ['status' => 'ok'];
    }

    /**
     * Returns the list of nominations made by $nominator (if non-null),
     * assigned to $assignee (if non-null) or all nominations (if both
     * $nominator and $assignee are null).
     *
     * @param Request $r         The request.
     * @param int     $nominator The user id of the person that made the
     *                           nomination.  May be null.
     * @param int     $assignee  The user id of the person assigned to review
     *                           nominations.  May be null.
     *
     * @return array The response.
     */
    private static function getListImpl(Request $r, $nominator, $assignee) {
        Validators::isNumber($r['page'], 'page', false);
        Validators::isNumber($r['page_size'], 'page_size', false);

        $page = (isset($r['page']) ? intval($r['page']) : 1);
        $pageSize = (isset($r['page_size']) ? intval($r['page_size']) : 1000);

        $nominations = null;
        try {
            $nominations = QualityNominationsDAO::getNominations(
                $nominator,
                $assignee,
                $page,
                $pageSize
            );
        } catch (Exception $e) {
            throw new InvalidDatabaseOperationException($e);
        }

        return [
            'status' => 'ok',
            'nominations' => $nominations,
        ];
    }

    /**
     * Validates that the user making the request is member of the
     * `omegaup:quality-reviewer` group.
     *
     * @param Request $r The request.
     *
     * @return void
     * @throws ForbiddenAccessException
     */
    private static function validateMemberOfReviewerGroup(Request $r) {
        $qualityReviewerGroup = GroupsDAO::findByAlias(
            Authorization::QUALITY_REVIEWER_GROUP_ALIAS
        );
        if (!Authorization::isGroupMember($r['current_user_id'], $qualityReviewerGroup)) {
            throw new ForbiddenAccessException('userNotAllowed');
        }
    }

    /**
     * Displays all the nominations.
     *
     * @param Request $r
     * @return array
     * @throws ForbiddenAccessException
     * @throws InvalidDatabaseOperationException
     */
    public static function apiList(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Validate request
        self::authenticateRequest($r);
        self::validateMemberOfReviewerGroup($r);

        return self::getListImpl($r, null /* nominator */, null /* assignee */);
    }

    /**
     * Displays the nominations that this user has been assigned.
     *
     * @param Request $r
     * @return array
     * @throws ForbiddenAccessException
     * @throws InvalidDatabaseOperationException
     */
    public static function apiMyAssignedList(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Validate request
        self::authenticateRequest($r);
        self::validateMemberOfReviewerGroup($r);

        return self::getListImpl($r, null /* nominator */, $r['current_user_id']);
    }

    /**
     * Displays the nominations that this user has created. The user does
     * not need to be a member of the reviewer group.
     *
     * @param Request $r
     * @return array
     * @throws ForbiddenAccessException
     * @throws InvalidDatabaseOperationException
     */
    public static function apiMyList(Request $r) {
        if (OMEGAUP_LOCKDOWN) {
            throw new ForbiddenAccessException('lockdown');
        }

        // Validate request
        self::authenticateRequest($r);

        return self::getListImpl($r, $r['current_user_id'], null /* assignee */);
    }
}
