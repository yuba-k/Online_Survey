-- Additional seed data for join/integration tests.
-- Uses the jt_20260702_* prefix to avoid collisions with existing rows.
-- Run order: schema.sql -> db_test.sql -> db_test_extra.sql

-- ========================================
-- 0. Reset all existing test data
-- ========================================
DELETE FROM likes;
DELETE FROM comments;
DELETE FROM responses;
DELETE FROM surveys;
DELETE FROM users;

-- ========================================
-- 1. Test users
-- ========================================
INSERT INTO users (account_name, password_hash, created_at, updated_at)
VALUES
  ('jt_20260702_alice', 'jt_hash_alice_20260702', NOW(), NOW()),
  ('jt_20260702_bob',   'jt_hash_bob_20260702',   NOW(), NOW()),
  ('jt_20260702_carla', 'jt_hash_carla_20260702', NOW(), NOW()),
  ('jt_20260702_dan',   'jt_hash_dan_20260702',   NOW(), NOW())
ON CONFLICT (account_name) DO NOTHING;

-- ========================================
-- 2. Surveys
-- ========================================
INSERT INTO surveys (
    creator_id, question_key, result_key, title, is_notified,
    survey_spec, start_at, end_at, created_at, updated_at
)
SELECT
    u.user_id,
    'jt_20260702_q_pc',
    'jt_20260702_r_pc',
  'JT20260702 PC Environment Survey',
    FALSE,
  '{"title":"PC usage overview","Survey_tag":["PC","work","hobby"],"aggregate":{"gender":true,"age":true,"gender_split":false},"questions":[{"id":"q0","type":"single","label":"Main OS?","options":["Windows","Mac","Linux"],"result_display":"pie"},{"id":"q1","type":"multiple","label":"Typical use cases?","options":["Work","Gaming","Video editing","Development"],"result_display":"bar"},{"id":"q2","type":"text","label":"Free text"}]}',
    NOW() - INTERVAL '10 days',
    NOW() + INTERVAL '20 days',
    NOW(),
    NOW()
FROM users u
WHERE u.account_name = 'jt_20260702_alice'
ON CONFLICT (question_key) DO NOTHING;

INSERT INTO surveys (
    creator_id, question_key, result_key, title, is_notified,
    survey_spec, start_at, end_at, created_at, updated_at
)
SELECT
    u.user_id,
    'jt_20260702_q_free',
    'jt_20260702_r_free',
  'JT20260702 Free Text Survey',
    TRUE,
  '{"title":"Free text focused survey","Survey_tag":["free-text","UI"],"aggregate":{"gender":false,"age":true,"gender_split":true},"questions":[{"id":"q0","type":"text","label":"What should be improved?"},{"id":"q1","type":"text","label":"What is hard to use?"}]}',
    NOW() - INTERVAL '30 days',
    NOW() - INTERVAL '1 day',
    NOW(),
    NOW()
FROM users u
WHERE u.account_name = 'jt_20260702_bob'
ON CONFLICT (question_key) DO NOTHING;

INSERT INTO surveys (
    creator_id, question_key, result_key, title, is_notified,
    survey_spec, start_at, end_at, created_at, updated_at
)
SELECT
    u.user_id,
    'jt_20260702_q_mix',
    'jt_20260702_r_mix',
    'JT20260702 Mixed Survey',
    FALSE,
    '{"title":"Mixed question types","Survey_tag":["single-choice","multi-choice","text"],"aggregate":{"gender":true,"age":true,"gender_split":true},"questions":[{"id":"q0","type":"single","label":"Usage frequency","options":["Daily","Several times a week","Several times a month"],"result_display":"bar"},{"id":"q1","type":"multiple","label":"Things you care about","options":["Speed","Design","Usability","Notifications"],"result_display":"table"},{"id":"q2","type":"text","label":"Requests"}]}',
    NOW() + INTERVAL '2 days',
    NOW() + INTERVAL '60 days',
    NOW(),
    NOW()
FROM users u
WHERE u.account_name = 'jt_20260702_carla'
ON CONFLICT (question_key) DO NOTHING;

-- ========================================
-- 3. Responses
-- ========================================
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, u.user_id, '{"q0":"Windows","q1":["Work","Development"],"q2":"More shortcuts would help"}', 29, 1, NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_bob'
WHERE s.question_key = 'jt_20260702_q_pc'
ON CONFLICT (survey_id, user_id) DO NOTHING;

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, u.user_id, '{"q0":"Mac","q1":["Video editing"],"q2":"Please add more color options"}', 34, 2, NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_carla'
WHERE s.question_key = 'jt_20260702_q_pc'
ON CONFLICT (survey_id, user_id) DO NOTHING;

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, NULL, '{"q0":"Linux","q1":["Development"],"q2":"Lightweight is important"}', 41, 1, NOW()
FROM surveys s
WHERE s.question_key = 'jt_20260702_q_pc'
  AND NOT EXISTS (
      SELECT 1 FROM responses r
      WHERE r.survey_id = s.survey_id
        AND r.user_id IS NULL
        AND r.answer_data = '{"q0":"Linux","q1":["Development"],"q2":"Lightweight is important"}'::jsonb
  );

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, u.user_id, '{"q0":"The explanation is easy to read","q1":"More examples would help"}', 22, 2, NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_alice'
WHERE s.question_key = 'jt_20260702_q_free'
ON CONFLICT (survey_id, user_id) DO NOTHING;

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, NULL, '{"q0":"The flow is a little hard to follow","q1":"Please improve search"}', 38, 1, NOW()
FROM surveys s
WHERE s.question_key = 'jt_20260702_q_free'
  AND NOT EXISTS (
      SELECT 1 FROM responses r
      WHERE r.survey_id = s.survey_id
        AND r.user_id IS NULL
        AND r.answer_data = '{"q0":"The flow is a little hard to follow","q1":"Please improve search"}'::jsonb
  );

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, u.user_id, '{"q0":"Daily","q1":["Speed","Usability"],"q2":"A dark theme would be nice"}', 27, 1, NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_dan'
WHERE s.question_key = 'jt_20260702_q_mix'
ON CONFLICT (survey_id, user_id) DO NOTHING;

INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
SELECT s.survey_id, NULL, '{"q0":"Several times a week","q1":["Notifications"],"q2":"Please make mobile input easier"}', 19, 2, NOW()
FROM surveys s
WHERE s.question_key = 'jt_20260702_q_mix'
  AND NOT EXISTS (
      SELECT 1 FROM responses r
      WHERE r.survey_id = s.survey_id
        AND r.user_id IS NULL
        AND r.answer_data = '{"q0":"Several times a week","q1":["Notifications"],"q2":"Please make mobile input easier"}'::jsonb
  );

-- ========================================
-- 4. Comments
-- ========================================
INSERT INTO comments (survey_id, user_id, content, created_at)
SELECT s.survey_id, u.user_id, 'JT20260702 Comment A: The comparison view is useful.', NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_bob'
WHERE s.question_key = 'jt_20260702_q_pc'
  AND NOT EXISTS (
      SELECT 1 FROM comments c
      WHERE c.survey_id = s.survey_id
        AND c.user_id = u.user_id
        AND c.content = 'JT20260702 Comment A: The comparison view is useful.'
  );

INSERT INTO comments (survey_id, user_id, content, created_at)
SELECT s.survey_id, u.user_id, 'JT20260702 Comment B: The amount of free text feels right.', NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_carla'
WHERE s.question_key = 'jt_20260702_q_free'
  AND NOT EXISTS (
      SELECT 1 FROM comments c
      WHERE c.survey_id = s.survey_id
        AND c.user_id = u.user_id
        AND c.content = 'JT20260702 Comment B: The amount of free text feels right.'
  );

INSERT INTO comments (survey_id, user_id, content, created_at)
SELECT s.survey_id, u.user_id, 'JT20260702 Comment C: I want a better multi-select result view.', NOW()
FROM surveys s
JOIN users u ON u.account_name = 'jt_20260702_alice'
WHERE s.question_key = 'jt_20260702_q_mix'
  AND NOT EXISTS (
      SELECT 1 FROM comments c
      WHERE c.survey_id = s.survey_id
        AND c.user_id = u.user_id
        AND c.content = 'JT20260702 Comment C: I want a better multi-select result view.'
  );

-- ========================================
-- 5. Likes
-- ========================================
INSERT INTO likes (user_id, comment_id, like_type, created_at)
SELECT u.user_id, c.comment_id, 1, NOW()
FROM users u
JOIN comments c ON c.content = 'JT20260702 Comment A: The comparison view is useful.'
WHERE u.account_name = 'jt_20260702_alice'
  AND NOT EXISTS (
      SELECT 1 FROM likes l
      WHERE l.user_id = u.user_id
        AND l.comment_id = c.comment_id
        AND l.like_type = 1
  );

INSERT INTO likes (user_id, comment_id, like_type, created_at)
SELECT u.user_id, c.comment_id, 1, NOW()
FROM users u
JOIN comments c ON c.content = 'JT20260702 Comment A: The comparison view is useful.'
WHERE u.account_name = 'jt_20260702_dan'
  AND NOT EXISTS (
      SELECT 1 FROM likes l
      WHERE l.user_id = u.user_id
        AND l.comment_id = c.comment_id
        AND l.like_type = 1
  );

INSERT INTO likes (user_id, comment_id, like_type, created_at)
SELECT u.user_id, c.comment_id, 2, NOW()
FROM users u
JOIN comments c ON c.content = 'JT20260702 Comment B: The amount of free text feels right.'
WHERE u.account_name = 'jt_20260702_alice'
  AND NOT EXISTS (
      SELECT 1 FROM likes l
      WHERE l.user_id = u.user_id
        AND l.comment_id = c.comment_id
        AND l.like_type = 2
  );

INSERT INTO likes (user_id, comment_id, like_type, created_at)
SELECT u.user_id, c.comment_id, 1, NOW()
FROM users u
JOIN comments c ON c.content = 'JT20260702 Comment C: I want a better multi-select result view.'
WHERE u.account_name = 'jt_20260702_bob'
  AND NOT EXISTS (
      SELECT 1 FROM likes l
      WHERE l.user_id = u.user_id
        AND l.comment_id = c.comment_id
        AND l.like_type = 1
  );
