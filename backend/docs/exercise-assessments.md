# Exercise and assessment API

Exercises remain owned by one lesson and center. Lesson revisions and blocks keep their existing publication and positioning responsibilities. New question types are added to the existing question, attempt, and answer records.

## Draft authoring

Create a draft with `POST /api/centers/{center}/admin/courses/{course}/lessons/{lesson}/exercises`, then save the full draft aggregate with `PATCH /api/centers/{center}/admin/courses/{course}/lessons/{lesson}/exercises/{exercise}`. The GET collection and item routes return answer configuration only to authorized administrators. Save Draft does not publish. Publishing uses the existing `/publish` route and rejects incomplete questions. Insert a published exercise into the lesson's editable revision with the existing lesson block route.

The API accepts these question values:

- `single_choice`: at least two options and exactly one correct option.
- `true_false`: exactly two options and one correct option.
- `multiple_select`: at least three options, at least two correct options, and one incorrect option. Grading awards full points only for an exact option-ID set; order is ignored.
- `short_answer`: no choice options. `answer_config` contains one to 20 `accepted_answers` (up to 500 characters each) and `normalization` settings. Student input is limited to 5,000 characters.

## Short-answer normalization

The default settings are case-insensitive, trim outer whitespace, collapse repeated internal whitespace, preserve punctuation, and preserve diacritics. Matching is exact after those configured transformations; there is no fuzzy or AI grading. Accepted answers that become duplicates after normalization are rejected.

NFC normalization requires PHP's `intl` extension, declared as a Composer platform requirement. Composer installation fails clearly when it is missing; the grader also raises an explicit runtime error if deployment bypasses Composer checks. The frontend and backend run the same committed Unicode normalization fixtures. Case folding uses JavaScript Unicode lowercase and PHP `mbstring` lowercase.

## Student answer payloads

Each item in `answers` contains a `question_id` and exactly the response field for that question type:

```json
{ "question_id": 10, "selected_option_id": 41 }
{ "question_id": 11, "selected_option_ids": [52, 53] }
{ "question_id": 12, "text_answer": "I am ready" }
```

The server checks ownership against the attempt snapshots and performs all scoring. Client-supplied correctness, score, snapshot, ownership, and other unknown fields are rejected. Attempt responses never contain accepted answers or grading configuration before the configured review release.

New clients must submit to `POST /api/centers/{center}/courses/{course}/learn/lessons/{lesson}/exercises/{exercise}/attempts/{attempt}/submit`; this is the only retry-safe contract. The deprecated `/attempts/current/submit` route can submit the first active attempt, but returns HTTP 409 when no attempt is active or once any earlier attempt is complete. This prevents a delayed request from being applied to a newer attempt. A retry against the explicit route with the same canonical request returns the persisted result; a different request returns HTTP 409. A completed attempt's score, answer rows, and timestamps remain unchanged.

## Persistence and compatibility

Migration `2026_11_24_100000_extend_exercise_answers_for_new_question_types` adds nullable JSON/text snapshots and a nullable submission fingerprint. Existing single-choice and true/false columns and rows remain intact. The old correct-option snapshot is nullable for question types without one correct option. Existing passing scores are not updated; the database default for new exercises is 70.
