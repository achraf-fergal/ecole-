import { chromium } from "playwright";
import { PDFDocument } from "pdf-lib";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { execSync } from "node:child_process";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, "../..");
const FIXTURE_PHP = path.join(REPO_ROOT, "backend/tests/fixtures/E2EFixtureManager.php");

const BASE_URL = process.env.BASE_URL || "http://127.0.0.1:3000";
const API_URL = process.env.API_URL || "http://127.0.0.1:8000";

async function getAdminExercises(page, fixture) {
  return page.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId }) => {
    const response = await fetch(
      `${apiUrl}/api/centers/${encodeURIComponent(centerSlug)}/admin/courses/${encodeURIComponent(courseSlug)}/lessons/${lessonId}/exercises`,
      {
        credentials: "include",
        headers: { Accept: "application/json" },
      }
    );
    const json = await response.json();
    return { status: response.status, exercises: json.data ?? json };
  }, {
    apiUrl: API_URL,
    centerSlug: fixture.centerSlug,
    courseSlug: fixture.courseSlug,
    lessonId: fixture.lessonId,
  });
}

async function verifyAdminExerciseDraftBuilder(page, fixture, intentionalFailures) {
  await page.getByRole("button", { name: "Exercise Drafts", exact: true }).click();
  const builder = page.getByRole("region", { name: "Exercise draft builder" });
  await builder.waitFor({ state: "visible" });

  const title = "E2E V2 Four Type Assessment";
  const createPromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith("/exercises") && response.request().method() === "POST";
  }, { timeout: 15000 });
  await builder.getByRole("button", { name: "+ New Draft", exact: true }).click();
  const createResponse = await createPromise;
  assert.equal(createResponse.status(), 201, "Creating an exercise draft returns HTTP 201.");
  const createBody = await createResponse.json();
  const exerciseId = Number((createBody.data ?? createBody).id);
  assert.ok(exerciseId > 0, "Created exercise draft has an ID.");

  await builder.getByLabel("Exercise title").fill(title);
  const questionCards = builder.locator("fieldset.rounded-xl.border-slate-200");
  const addQuestion = builder.getByRole("button", { name: "+ Add question", exact: true });
  for (let index = 0; index < 4; index += 1) await addQuestion.click();
  assert.equal(await questionCards.count(), 4, "Draft builder adds four questions.");

  const prompts = [
    "Choose the greeting.",
    "The sky is blue.",
    "Select the colors of the Irish flag.",
    "Translate hello into French.",
  ];
  for (let index = 0; index < prompts.length; index += 1) {
    await questionCards.nth(index).getByLabel("Prompt").fill(prompts[index]);
  }

  await questionCards.nth(0).getByLabel("Question type").selectOption("single_choice");
  await questionCards.nth(0).getByLabel("Question 1 option 1").fill("Salut");
  await questionCards.nth(0).getByLabel("Question 1 option 2").fill("Au revoir");
  await questionCards.nth(0).getByLabel("Mark option 2 correct").check();
  await questionCards.nth(0).getByRole("button", { name: "+ Add option", exact: true }).click();
  await questionCards.nth(0).getByLabel("Question 1 option 3").fill("Temporary option");
  await questionCards.nth(0).getByRole("button", { name: "Remove option 3", exact: true }).click();
  assert.equal(await questionCards.nth(0).getByLabel("Question 1 option 3").count(), 0, "Draft builder removes an unused option.");

  await questionCards.nth(1).getByLabel("Question type").selectOption("multiple_select");
  await questionCards.nth(1).getByLabel("Question type").selectOption("true_false");
  await questionCards.nth(1).getByLabel("Mark option 1 correct").check();

  await questionCards.nth(2).getByLabel("Question type").selectOption("multiple_select");
  await questionCards.nth(2).getByLabel("Question 3 option 1").fill("Green");
  await questionCards.nth(2).getByLabel("Question 3 option 2").fill("Orange");
  await questionCards.nth(2).getByLabel("Question 3 option 3").fill("White");
  await questionCards.nth(2).getByLabel("Mark option 1 correct").check();
  await questionCards.nth(2).getByLabel("Mark option 2 correct").check();
  await questionCards.nth(2).getByLabel("Mark option 3 correct").uncheck();

  await questionCards.nth(3).getByLabel("Question type").selectOption("short_answer");
  await questionCards.nth(3).getByLabel("Explanation (shown only when answer review is allowed)").fill("Bonjour means hello.");

  let prematurePublishRequests = 0;
  const onPrematurePublish = (request) => {
    if (new URL(request.url()).pathname.endsWith(`/exercises/${exerciseId}/publish`)) {
      prematurePublishRequests += 1;
    }
  };
  page.on("request", onPrematurePublish);
  await builder.getByRole("button", { name: "Publish", exact: true }).click();
  await builder.getByRole("alert").filter({ hasText: "Review the marked fields before publishing." }).waitFor({ timeout: 5000 });
  await builder.getByRole("alert").filter({ hasText: "Add at least one accepted answer." }).waitFor({ timeout: 5000 });
  await page.waitForTimeout(100);
  page.off("request", onPrematurePublish);
  assert.equal(prematurePublishRequests, 0, "Invalid exercise configuration is rejected before publishing.");
  const invalidDraft = await getAdminExercises(page, fixture);
  assert.equal(invalidDraft.status, 200);
  assert.equal(invalidDraft.exercises.find((row) => row.id === exerciseId)?.status, "draft", "Failed publish leaves the exercise as a draft.");

  await questionCards.nth(3).locator('input[aria-label="Accepted answer 1"]').fill("bonjour");
  await questionCards.nth(3).getByRole("button", { name: "+ Add accepted answer", exact: true }).click();
  await questionCards.nth(3).locator('input[aria-label="Accepted answer 2"]').fill("salut");
  await builder.getByLabel("Show correct answers and explanations after submission").check();

  const exerciseUrl = `${API_URL}/api/centers/${encodeURIComponent(fixture.centerSlug)}/admin/courses/${encodeURIComponent(fixture.courseSlug)}/lessons/${fixture.lessonId}/exercises/${exerciseId}`;
  const exercisePath = new URL(exerciseUrl).pathname;
  intentionalFailures.add(`500:${exercisePath}`);
  let failedSaveInjected = false;
  await page.route(exerciseUrl, async (route) => {
    if (route.request().method() === "PATCH" && !failedSaveInjected) {
      failedSaveInjected = true;
      await route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "Injected one-time E2E save failure." }),
      });
      return;
    }
    await route.continue();
  });

  const failedSavePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === exercisePath && response.request().method() === "PATCH",
  { timeout: 15000 });
  await builder.getByRole("button", { name: "Save Draft", exact: true }).click();
  const failedSaveResponse = await failedSavePromise;
  assert.equal(failedSaveResponse.status(), 500, "Injected transient exercise save fails as expected.");
  await builder.getByRole("alert").first().waitFor({ state: "visible", timeout: 5000 });
  assert.equal(await builder.getByLabel("Exercise title").inputValue(), title, "Failed save preserves the exercise title.");
  assert.equal(await questionCards.nth(3).locator('input[aria-label="Accepted answer 1"]').inputValue(), "bonjour", "Failed save preserves accepted-answer edits.");
  await page.unroute(exerciseUrl);

  const initialSavePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === exercisePath && response.request().method() === "PATCH",
  { timeout: 15000 });
  await builder.getByRole("button", { name: "Save Draft", exact: true }).click();
  const initialSaveResponse = await initialSavePromise;
  assert.equal(initialSaveResponse.status(), 200, "Exercise draft saves after retry.");

  await page.reload({ waitUntil: "networkidle" });
  await page.getByRole("button", { name: "Exercise Drafts", exact: true }).click();
  const reopenedBuilder = page.getByRole("region", { name: "Exercise draft builder" });
  const savedExerciseButton = reopenedBuilder.getByRole("button").filter({ hasText: title });
  await savedExerciseButton.waitFor({ state: "visible", timeout: 10000 });
  await savedExerciseButton.click();

  const beforeResponse = await getAdminExercises(page, fixture);
  assert.equal(beforeResponse.status, 200);
  const before = beforeResponse.exercises.find((row) => row.id === exerciseId);
  assert.ok(before, "Saved exercise reopens from the server.");
  assert.deepEqual(before.questions.map((question) => question.question_type), [
    "single_choice", "true_false", "multiple_select", "short_answer",
  ]);
  assert.deepEqual(before.questions.map((question) => question.prompt), prompts);
  assert.deepEqual(before.questions[3].answer_config.accepted_answers, ["bonjour", "salut"]);
  const originalQuestionIds = before.questions.map((question) => Number(question.id));
  const originalSingleOptionIds = before.questions[0].options.map((option) => Number(option.id));
  const originalMultipleOptionIds = before.questions[2].options.map((option) => Number(option.id));

  const reopenedCards = reopenedBuilder.locator("fieldset.rounded-xl.border-slate-200");
  await reopenedCards.nth(0).getByLabel("Question 1 option 2").fill("Goodbye");
  await reopenedCards.nth(0).getByLabel("Mark option 1 correct").check();
  await reopenedCards.nth(1).getByLabel("Question type").selectOption("multiple_select");
  await reopenedCards.nth(1).getByLabel("Question type").selectOption("true_false");
  await reopenedCards.nth(1).getByLabel("Mark option 1 correct").check();
  await reopenedCards.nth(2).getByRole("button", { name: "+ Add option", exact: true }).click();
  await reopenedCards.nth(2).getByLabel("Question 3 option 4").fill("Temporary option");
  await reopenedCards.nth(2).getByRole("button", { name: "Remove option 4", exact: true }).click();
  await reopenedCards.nth(3).locator('input[aria-label="Accepted answer 1"]').fill("Bonjour");
  await reopenedCards.nth(3).locator('input[aria-label="Accepted answer 2"]').fill("salut");

  const editSavePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === exercisePath && response.request().method() === "PATCH",
  { timeout: 15000 });
  await reopenedBuilder.getByRole("button", { name: "Save Draft", exact: true }).click();
  const editSaveResponse = await editSavePromise;
  assert.equal(editSaveResponse.status(), 200, "Edited exercise draft saves.");

  const afterResponse = await getAdminExercises(page, fixture);
  const after = afterResponse.exercises.find((row) => row.id === exerciseId);
  assert.deepEqual(after.questions.map((question) => Number(question.id)), originalQuestionIds, "Question IDs remain stable after edits and a type change.");
  assert.deepEqual(after.questions[0].options.map((option) => Number(option.id)), originalSingleOptionIds, "Single-choice option IDs remain stable when edited.");
  assert.deepEqual(after.questions[2].options.map((option) => Number(option.id)), originalMultipleOptionIds, "Multiple-select option IDs remain stable after add/remove.");
  assert.equal(after.questions[0].options[0].is_correct, true, "Correct-answer edits persist.");
  assert.deepEqual(after.questions[3].answer_config.accepted_answers, ["Bonjour", "salut"], "Accepted-answer edits persist.");

  await reopenedBuilder.getByRole("button", { name: "Preview", exact: true }).click();
  const preview = page.getByRole("dialog");
  await preview.waitFor({ state: "visible" });
  assert.ok((await preview.innerText()).includes("no attempt is created"), "Admin preview explains that it creates no attempt.");
  for (const prompt of prompts) assert.ok((await preview.innerText()).includes(prompt), `Preview includes: ${prompt}`);
  await preview.getByRole("button", { name: "Close", exact: true }).click();

  const finalSavePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === exercisePath && response.request().method() === "PATCH",
  { timeout: 15000 });
  const publishPromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith(`/exercises/${exerciseId}/publish`) && response.request().method() === "POST";
  }, { timeout: 15000 });
  await reopenedBuilder.getByRole("button", { name: "Publish", exact: true }).click();
  const [finalSaveResponse, publishResponse] = await Promise.all([finalSavePromise, publishPromise]);
  assert.equal(finalSaveResponse.status(), 200, "Publish saves the latest exercise draft first.");
  assert.equal(publishResponse.status(), 200, "Valid exercise publishes successfully.");

  const publishedResponse = await getAdminExercises(page, fixture);
  const published = publishedResponse.exercises.find((row) => row.id === exerciseId);
  assert.equal(published?.status, "published", "Published status persists.");
  assert.deepEqual(published.questions.map((question) => question.question_type), [
    "single_choice", "true_false", "multiple_select", "short_answer",
  ]);

  const insertPromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.endsWith("/blocks/exercise") && response.request().method() === "POST";
  }, { timeout: 15000 });
  await reopenedBuilder.getByRole("button", { name: "Insert into lesson", exact: true }).click();
  const insertResponse = await insertPromise;
  assert.ok([200, 201].includes(insertResponse.status()), "Published exercise inserts into the draft lesson revision.");
  await page.getByText(title, { exact: true }).first().waitFor({ state: "visible", timeout: 10000 });

  return published;
}

/**
 * Resolve a Chromium-compatible browser without assuming hardcoded paths.
 */
async function launchBrowser() {
  const customExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  if (customExecutable && fs.existsSync(customExecutable)) {
    return await chromium.launch({ executablePath: customExecutable, headless: true });
  }

  const possiblePaths = [
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
  ];

  for (const p of possiblePaths) {
    if (fs.existsSync(p)) {
      return await chromium.launch({ executablePath: p, headless: true });
    }
  }

  const channel = process.env.BROWSER_CHANNEL;
  if (channel) {
    return await chromium.launch({ channel, headless: true });
  }

  return await chromium.launch({ headless: true });
}

/**
 * Setup an isolated E2E test fixture in backend.
 */
function setupFixture() {
  console.log("Setting up isolated E2E test fixture in backend...");
  const stdout = execSync(`php "${FIXTURE_PHP}" setup`, {
    encoding: "utf8",
    timeout: 30000,
  });
  const data = JSON.parse(stdout.trim());
  console.log(`✓ Isolated fixture created: Center ID ${data.centerId} (${data.centerSlug}), Lesson ID ${data.lessonId}`);
  return data;
}

/**
 * Teardown the isolated fixture in backend.
 * Propagates any teardown failure and asserts status is 'cleaned'.
 */
function teardownFixture(centerId) {
  if (!centerId) return;
  console.log(`Tearing down isolated fixture Center ID ${centerId}...`);
  const stdout = execSync(`php "${FIXTURE_PHP}" teardown ${centerId}`, {
    encoding: "utf8",
    timeout: 30000,
  });
  const data = JSON.parse(stdout.trim());
  if (data.status !== "cleaned" || Number(data.centerId) !== Number(centerId)) {
    throw new Error(`Teardown validation failed: status=${data.status}, centerId=${data.centerId}`);
  }
  console.log(`✓ Teardown complete: Center ID ${data.centerId} cleanly removed.`);
}

async function checkService(name, url) {
  try {
    const response = await fetch(url, {
      signal: AbortSignal.timeout(5000),
      redirect: "manual",
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    console.log(`✓ ${name} is reachable: ${url}`);
  } catch (error) {
    throw new Error(
      `${name} is not available at ${url}. `
      + `Start the required server before running acceptance tests.`,
      { cause: error }
    );
  }
}

async function verifyRequiredServices() {
  console.log("\nPreflight: checking application servers...");

  await checkService(
    "Next.js Frontend",
    `${BASE_URL}/login`
  );

  await checkService(
    "Laravel Backend",
    `${API_URL}/api/auth/csrf-cookie`
  );

  console.log("✓ All required application services are ready.");
}

async function main() {
  console.log("=================================================");
  console.log("G5.2.1 Production-Grade Reliable Browser Acceptance Suite");
  console.log("=================================================");

  let fixture = null;
  let browser = null;
  let primaryError = null;
  let authoredExercise = null;
  const intentionalAcceptanceFailures = new Set();
  const consoleErrors = [];
  const failedRequests = [];

  try {
    // 1. Validate frontend and backend first.
    await verifyRequiredServices();

    // 2. Launch browser before touching the database.
    browser = await launchBrowser();

    // 3. Create isolated fixture only after prerequisites pass.
    fixture = setupFixture();

    // Continue with existing admin/student workflows...

    // -----------------------------------------------------------------------
    // ASSERTION 1: Admin Authentication
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 1] Admin Authentication...");
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();

    // Keep provider pages deterministic: this acceptance verifies our URLs,
    // authorization, persistence, and iframe wiring, not third-party playback.
    await adminPage.route("https://www.youtube-nocookie.com/**", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/html",
        body: "<!doctype html><title>Mock YouTube player</title>",
      })
    );
    await adminPage.route("https://drive.google.com/**", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/html",
        body: "<!doctype html><title>Mock Drive preview</title>",
      })
    );

    adminPage.on("console", (msg) => {
      if (msg.type() === "error") consoleErrors.push(`[Admin Browser Error] ${msg.text()}`);
    });
    adminPage.on("pageerror", (err) => {
      consoleErrors.push(`[Admin Page Error] ${err.message}`);
    });
    adminPage.on("response", (resp) => {
      if (resp.status() >= 400 && !resp.url().includes("/favicon.ico")) {
        failedRequests.push({ url: resp.url(), status: resp.status() });
      }
    });
    adminPage.on("dialog", async (dialog) => {
      console.log(`[Admin Dialog] Auto-accepting dialog: "${dialog.message()}"`);
      await dialog.accept();
    });

    await adminPage.goto(`${BASE_URL}/login`, { waitUntil: "networkidle" });
    await adminPage.fill('input[name="email"]', fixture.adminEmail);
    await adminPage.fill('input[name="password"]', fixture.adminPassword);

    const loginResponsePromise = adminPage.waitForResponse(
      (resp) => {
        const url = new URL(resp.url());

        return (
          url.pathname === "/api/auth/login" &&
          resp.request().method() === "POST"
        );
      },
      { timeout: 15000 }
    );

    await adminPage.click('button[type="submit"]');

    const loginResponse = await loginResponsePromise;

    const loginBody = await loginResponse.text();

    assert.equal(
      loginResponse.status(),
      200,
      `Login failed: HTTP ${loginResponse.status()}\n${loginBody}`
    );

    await adminPage.waitForURL(
      (url) => url.pathname === "/admin/users",
      { timeout: 10000 }
    );
    console.log("✓ Center admin authenticated successfully. Landed at:", adminPage.url());

    // -----------------------------------------------------------------------
    // ASSERTION 2: Opening Lesson Editor
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 2] Opening Lesson Editor for Isolated Lesson...");
    const lessonEditorUrl = `${BASE_URL}/admin/learning/centers/${fixture.centerSlug}/courses/${fixture.courseSlug}/lessons/${fixture.lessonId}`;

    const lessonPageRes = await adminPage.goto(lessonEditorUrl, { waitUntil: "networkidle" });
    assert.equal(lessonPageRes.status(), 200, "Lesson editor page returned HTTP 200");
    await adminPage.waitForSelector("h1", { state: "visible" });
    await adminPage.waitForSelector('h2:has-text("Content Composer")', { state: "visible" });

    // Check H1 and H2 hierarchy
    const h1Heading = await adminPage.locator("h1").first().innerText();
    assert.ok(h1Heading.includes("E2E Lesson"), `H1 contains lesson title: ${h1Heading}`);

    const h2Elements = await adminPage.locator("h2").allInnerTexts();
    assert.ok(h2Elements.some((h) => /Content Composer/i.test(h)), "H2 'Content Composer' is present");
    assert.ok(h2Elements.some((h) => /Curriculum Timeline/i.test(h)), "H2 'Curriculum Timeline' is present");
    assert.ok(h2Elements.some((h) => /Revision History/i.test(h)), "H2 'Revision History' is present");
    console.log("✓ Lesson editor loaded with proper semantic heading hierarchy.");

    // -----------------------------------------------------------------------
    // ASSERTION 3: Creating a Draft Revision
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 3] Ensuring Draft Revision exists...");
    const createDraftBtn = adminPage.locator('button:has-text("Create New Draft"), button:has-text("Create draft")');
    if ((await createDraftBtn.count()) > 0 && (await createDraftBtn.first().isVisible())) {
      const draftApiPromise = adminPage.waitForResponse(
        (resp) => resp.url().includes("/revisions") && (resp.status() === 201 || resp.status() === 200)
      );
      await createDraftBtn.first().click();
      await draftApiPromise;
    }
    await adminPage.waitForSelector('text=Draft', { timeout: 5000 });
    console.log("✓ Draft revision is active for editing.");

    // -----------------------------------------------------------------------
    // ASSERTIONS 4 & 5: Editing Rich Text with Formatting (Bold, Italic, Headings, Lists)
    // -----------------------------------------------------------------------
    console.log("\n[Assertions 4 & 5] Editing Rich Text with TipTap formatting...");
    const addTextBtn = adminPage.locator('button:has-text("+ Add Text")');
    await addTextBtn.click();

    await adminPage.waitForSelector(".ProseMirror", { state: "visible" });
    const editor = adminPage.locator(".ProseMirror");
    await editor.click();
    await editor.fill("G5.2.1 Rigorous Production Acceptance: Verifying persisted TipTap formatting.");

    // Select text and apply Bold formatting
    await adminPage.keyboard.press("Control+A");
    const boldBtn = adminPage.locator('button[aria-label="Bold"]');
    assert.ok((await boldBtn.count()) > 0, "Bold formatting control present");
    await boldBtn.click();

    // Verify H1-H6 toolbar buttons exist
    for (let level = 1; level <= 6; level++) {
      const hBtn = adminPage.locator(`button[aria-label="Heading ${level}"]`);
      assert.ok((await hBtn.count()) > 0, `Toolbar includes H${level} control`);
    }

    // Verify formatting mark buttons
    const italicBtn = adminPage.locator('button[aria-label="Italic"]');
    const listBtn = adminPage.locator('button[aria-label="Bulleted list"]');
    assert.ok((await italicBtn.count()) > 0, "Italic formatting control present");
    assert.ok((await listBtn.count()) > 0, "List formatting control present");
    console.log("✓ Rich text editor formatting controls verified and formatting applied.");

    // -----------------------------------------------------------------------
    // ASSERTIONS 6 & 7: Saving Text Block and Verifying Persistence Across Reload
    // -----------------------------------------------------------------------
    console.log("\n[Assertions 6 & 7] Saving text block and testing reload persistence...");
    const saveTextBlockPromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/blocks/text") && (resp.status() === 201 || resp.status() === 200)
    );
    await adminPage.click('button:has-text("Insert Text Block")');
    const textSaveRes = await saveTextBlockPromise;
    assert.ok(textSaveRes.status() === 201 || textSaveRes.status() === 200, "Text block API saved successfully");

    // Reload page
    await adminPage.reload({ waitUntil: "networkidle" });
    await adminPage.waitForSelector("main", { state: "visible" });
    const timelineAfterReload = await adminPage.locator("main").innerText();
    assert.ok(
      timelineAfterReload.includes("G5.2.1 Rigorous Production Acceptance"),
      "Persisted text block formatting survived reload!"
    );

    // Verify formatting tag in rendered DOM
    const strongElement = adminPage.locator("strong");
    assert.ok((await strongElement.count()) > 0, "Strong tag persisted in rendered DOM across reload!");
    console.log("✓ Text block formatting verified persistent across reload in DOM.");

    // -----------------------------------------------------------------------
    // ASSERTION 8: Adding a Media Block
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 8] Adding Media Block from pre-created asset...");
    const addMediaBtn = adminPage.locator('button:has-text("+ Add Media")');
    await addMediaBtn.click();
    await adminPage.waitForSelector('h3:has-text("Add Media Block")', { timeout: 5000 });

    const assetSelect = adminPage.locator('select').filter({ hasText: 'Choose an uploaded asset' });
    await assetSelect.waitFor({ state: "visible" });
    await assetSelect.selectOption(String(fixture.imageAssetId || fixture.assetId));

    const attachMediaBtn = adminPage.locator('button:has-text("Insert Selected Media")');
    await attachMediaBtn.waitFor({ state: "visible" });
    const saveMediaPromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/blocks/media") && (resp.status() === 201 || resp.status() === 200)
    );
    await attachMediaBtn.click();
    const mediaRes = await saveMediaPromise;
    assert.ok(mediaRes.status() === 201 || mediaRes.status() === 200, "Media block API returned success");
    console.log("✓ Media block attached via API.");

    await adminPage.locator('button:has-text("+ Add Media")').click();
    await adminPage.waitForSelector('h3:has-text("Add Media Block")', { timeout: 5000 });
    const pdfAssetSelect = adminPage.locator('select').filter({
      hasText: "Choose an uploaded asset",
    });
    await pdfAssetSelect.selectOption(String(fixture.pdfAssetId));
    const attachPdfPromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/blocks/media") && (resp.status() === 201 || resp.status() === 200)
    );
    await adminPage.locator('button:has-text("Insert Selected Media")').click();
    const attachPdfResponse = await attachPdfPromise;
    assert.ok(
      attachPdfResponse.status() === 201 || attachPdfResponse.status() === 200,
      "Downloadable PDF is attached to the lesson revision."
    );

    const youtubeTitle = "Acceptance YouTube Video";
    const driveTitle = "Acceptance Google Drive Video";
    const externalVideoTitles = new Set([youtubeTitle, driveTitle]);

    async function addExternalVideo({ provider, url, title, caption }) {
      await adminPage.locator('button:has-text("+ Add Media")').click();
      await adminPage.waitForSelector('h3:has-text("Add Media Block")', { timeout: 5000 });

      const typeSelect = adminPage.locator("select").filter({
        has: adminPage.locator(`option[value="${provider}"]`),
      }).first();
      await typeSelect.selectOption(provider);
      await adminPage.locator('input[type="url"]').fill(url);
      await adminPage.locator('input[placeholder="e.g. Vocabulary Worksheet"]').fill(title);
      await adminPage.locator("textarea").last().fill(caption);

      if (provider === "youtube") {
        await adminPage.locator('input[type="url"]').fill(
          "https://youtube.com.evil.test/watch?v=M7lc1UVf-VE"
        );
        const rejectedRequest = adminPage.waitForResponse(
          (response) => {
            const requestUrl = new URL(response.url());
            return requestUrl.pathname.endsWith("/assets/external-video")
              && response.request().method() === "POST";
          },
          { timeout: 15000 }
        );
        await adminPage.locator('button:has-text("Add External Video")').click();
        const rejectedResponse = await rejectedRequest;
        const rejectedBody = await rejectedResponse.text();
        assert.equal(
          rejectedResponse.status(),
          422,
          `Deceptive YouTube host should be rejected: HTTP ${rejectedResponse.status()}\n${rejectedBody}`
        );
        await adminPage.locator('[role="alert"]').filter({ hasText: /YouTube/i }).waitFor({ state: "visible" });
        await adminPage.locator('input[type="url"]').fill(url);
      }

      const createRequest = adminPage.waitForResponse(
        (response) => {
          const requestUrl = new URL(response.url());
          return requestUrl.pathname.endsWith("/assets/external-video")
            && response.request().method() === "POST";
        },
        { timeout: 15000 }
      );
      await adminPage.locator('button:has-text("Add External Video")').click();
      const createResponse = await createRequest;
      const createBody = await createResponse.text();
      assert.equal(
        createResponse.status(),
        201,
        `Adding ${provider} asset failed: HTTP ${createResponse.status()}\n${createBody}`
      );
      const asset = (JSON.parse(createBody).data ?? JSON.parse(createBody));

      const previewSection = adminPage.getByText("Preview selected video", { exact: true }).locator("xpath=..");
      const previewButton = previewSection.getByRole("button", { name: "Load video" });
      await previewButton.waitFor({ state: "visible", timeout: 10000 });
      const accessRequest = adminPage.waitForResponse(
        (response) => {
          const requestUrl = new URL(response.url());
          return requestUrl.pathname.endsWith(`/assets/${asset.id}/access`)
            && response.request().method() === "GET";
        },
        { timeout: 15000 }
      );
      await previewButton.click();
      const accessResponse = await accessRequest;
      const accessBody = await accessResponse.text();
      assert.equal(
        accessResponse.status(),
        200,
        `Preview access failed: HTTP ${accessResponse.status()}\n${accessBody}`
      );
      const access = (JSON.parse(accessBody).data ?? JSON.parse(accessBody));
      assert.equal(access.type, "external_video");
      assert.equal(access.provider, provider);
      assert.ok(access.embed_url.startsWith("https://"));
      await adminPage.locator(`iframe[title="${title}"]`).waitFor({ state: "visible" });

      const mediaRequest = adminPage.waitForResponse(
        (response) => response.url().includes("/blocks/media")
          && response.request().method() === "POST",
        { timeout: 15000 }
      );
      await adminPage.locator('button:has-text("Insert Selected Media")').click();
      const mediaResponse = await mediaRequest;
      const mediaBody = await mediaResponse.text();
      assert.equal(
        mediaResponse.status(),
        201,
        `Inserting ${provider} video failed: HTTP ${mediaResponse.status()}\n${mediaBody}`
      );
      await adminPage.waitForSelector('h3:has-text("Add Media Block")', { state: "hidden" });

      return { assetId: asset.id, access };
    }

    console.log("\n[Assertion 8a] Adding, previewing, and inserting YouTube video...");
    await addExternalVideo({
      provider: "youtube",
      url: "https://www.youtube.com/watch?v=M7lc1UVf-VE",
      title: youtubeTitle,
      caption: "YouTube video acceptance caption.",
    });
    console.log("✓ YouTube asset created, safely previewed, and inserted into the draft.");

    console.log("\n[Assertion 8b] Adding, previewing, and inserting Google Drive video...");
    const driveFileId = "1AbCdEfGhIjKlMnOpQrStUvWxYz123456";
    await addExternalVideo({
      provider: "google_drive",
      url: `https://drive.google.com/file/d/${driveFileId}/view?usp=sharing`,
      title: driveTitle,
      caption: "Google Drive video acceptance caption.",
    });
    console.log("✓ Google Drive asset created, safely previewed, and inserted into the draft.");

    // -----------------------------------------------------------------------
    // ASSERTION 9: Adding an Exercise Block
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 9] Adding Assessment Exercise Block...");
    const addExerciseBtn = adminPage.locator('button:has-text("+ Add Exercise")');
    await addExerciseBtn.click();
    await adminPage.waitForSelector('h3:has-text("Add Assessment Exercise")', { timeout: 5000 });

    const exerciseSelect = adminPage.locator('select').filter({ hasText: 'Choose an exercise' });
    await exerciseSelect.waitFor({ state: "visible" });
    await exerciseSelect.selectOption(String(fixture.exerciseId));

    const attachExerciseBtn = adminPage.locator('button:has-text("Insert Exercise Block")');
    await attachExerciseBtn.waitFor({ state: "visible" });
    const saveExercisePromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/blocks/exercise") && (resp.status() === 201 || resp.status() === 200)
    );
    await attachExerciseBtn.click();
    const exerciseRes = await saveExercisePromise;
    assert.ok(exerciseRes.status() === 201 || exerciseRes.status() === 200, "Exercise block API returned success");
    await adminPage.getByText("E2E Assessment Quiz", { exact: true }).first().waitFor({
      state: "visible",
      timeout: 10000,
    });
    console.log("✓ Exercise block attached via API.");

    
    // -----------------------------------------------------------------------
    // ASSERTION 10: Reordering Blocks via UI and Verifying Persisted Order
    // -----------------------------------------------------------------------
    
    console.log(
      "\n[Assertion 10] Testing Block Reordering in Timeline via Accessible UI..."
    );
    
    console.log("\n[Assertion 9a] Admin exercise draft lifecycle and four question types...");
    authoredExercise = await verifyAdminExerciseDraftBuilder(
      adminPage,
      fixture,
      intentionalAcceptanceFailures
    );
    console.log("âœ“ Draft saved/reopened/edited; IDs, validation, failed-save recovery, preview, publication, and lesson insertion verified.");

    const dragHandles = adminPage.locator(
      'button[aria-label^="Reorder"]'
    );

    const dragCount = await dragHandles.count();
    
    assert.ok(
      dragCount >= 2,
      `Timeline has ${dragCount} blocks ready for reordering`
    );
    
    // Read canonical order before interaction.
    const draftBeforeReorder = await adminPage.evaluate(
      async ({ apiUrl, centerSlug, courseSlug, lessonId }) => {
        const response = await fetch(
          `${apiUrl}/api/centers/${centerSlug}/admin/courses/${courseSlug}/lessons/${lessonId}/content`,
          {
            credentials: "include",
            headers: {
              Accept: "application/json",
            },
          }
        );
    
        if (!response.ok) {
          throw new Error(
            `Cannot retrieve draft before reorder: HTTP ${response.status}`
          );
        }
    
        const json = await response.json();
        const payload = json.data ?? json;
    
        return payload.draft_revision;
      },
      {
        apiUrl: API_URL,
        centerSlug: fixture.centerSlug,
        courseSlug: fixture.courseSlug,
        lessonId: fixture.lessonId,
      }
    );
    
    assert.ok(
      draftBeforeReorder &&
      draftBeforeReorder.blocks.length >= 2,
      "Draft must contain multiple blocks."
    );
    
    const originalBlockIds = draftBeforeReorder.blocks.map(
      (block) => Number(block.id)
    );
    
    // Move the first block down by one position.
    const expectedOrderedIds = [...originalBlockIds];
    
    const [movedId] = expectedOrderedIds.splice(0, 1);
    
    expectedOrderedIds.splice(1, 0, movedId);
    
    assert.notDeepEqual(
      expectedOrderedIds,
      originalBlockIds,
      "Expected order must differ from the original order."
    );
    
    // Locate the first accessible drag handle.
    const firstHandle = dragHandles.first();
    
    await firstHandle.waitFor({
      state: "visible",
      timeout: 10000,
    });
    
    // Detect disabled controls before attempting keyboard interaction.
    assert.equal(
      await firstHandle.isEnabled(),
      true,
      "Reorder handle is disabled. Check isSubmitting and hasUnsavedChanges."
    );
    
    await firstHandle.focus();
    
    const focused = await firstHandle.evaluate(
      (element) => document.activeElement === element
    );
    
    assert.equal(
      focused,
      true,
      "Reorder handle did not receive keyboard focus."
    );
    
    // IMPORTANT:
    // Match the endpoint and method only.
    // Do not filter by HTTP 200 here, otherwise a 422 or 500
    // is hidden and becomes a misleading timeout.
    const reorderResponsePromise = adminPage.waitForResponse(
      (response) => {
        const url = new URL(response.url());
    
        return (
          url.pathname.endsWith("/blocks/order") &&
          response.request().method() === "PUT"
        );
      },
      {
        timeout: 15000,
      }
    );
    
    // Move the keyboard drag pointer until the adjacent block is the active
    // drop target. A fixed number of arrow presses is layout-dependent.
    const secondBlockArticle = dragHandles
      .nth(1)
      .locator("xpath=ancestor::article[1]");
    await adminPage.keyboard.press("Space");
    let adjacentBlockIsDropTarget = false;
    for (let step = 0; step < 40; step += 1) {
      await adminPage.keyboard.press("ArrowDown");
      adjacentBlockIsDropTarget = await secondBlockArticle.evaluate(
        (element) => element.className.includes("before:bg-[#0e8988]")
      );
      if (adjacentBlockIsDropTarget) break;
    }

    if (!adjacentBlockIsDropTarget) {
      await adminPage.keyboard.press("Escape");
    }
    assert.ok(
      adjacentBlockIsDropTarget,
      "Keyboard drag did not reach the immediately following block."
    );
    await adminPage.keyboard.press("Space");
    
    const capturedReorder = await reorderResponsePromise;
    
    // Read the response body before asserting its status,
    // so backend validation errors remain visible.
    const reorderResponseText = await capturedReorder.text();
    
    assert.equal(
      capturedReorder.status(),
      200,
      `Block reorder failed: HTTP ${capturedReorder.status()}\n${reorderResponseText}`
    );
    
    // Verify the exact request payload expected by Laravel.
    const reorderRequestBody =
      capturedReorder.request().postDataJSON();
    
    assert.ok(
      Array.isArray(reorderRequestBody.ordered_block_ids),
      "Request must contain ordered_block_ids."
    );
    
    assert.deepEqual(
      reorderRequestBody.ordered_block_ids.map(Number),
      expectedOrderedIds,
      "UI must submit the exact expected ordered_block_ids."
    );
    
    // Verify the canonical response returned by Laravel.
    const reorderResponseJson = JSON.parse(
      reorderResponseText
    );
    
    const returnedBlocks =
      reorderResponseJson.data ?? reorderResponseJson;
    
    assert.deepEqual(
      returnedBlocks.map((block) => Number(block.id)),
      expectedOrderedIds,
      "Backend must return the canonical reordered blocks."
    );
    
    console.log(
      "✓ UI keyboard interaction persisted the exact block order."
    );
    
    // -----------------------------------------------------------------------
    // Verify persistence after a real browser reload.
    // -----------------------------------------------------------------------
    
    await adminPage.reload({
      waitUntil: "networkidle",
    });
    
    const draftAfterReorder = await adminPage.evaluate(
      async ({ apiUrl, centerSlug, courseSlug, lessonId }) => {
        const response = await fetch(
          `${apiUrl}/api/centers/${centerSlug}/admin/courses/${courseSlug}/lessons/${lessonId}/content`,
          {
            credentials: "include",
            headers: {
              Accept: "application/json",
            },
          }
        );
    
        if (!response.ok) {
          throw new Error(
            `Cannot retrieve draft after reorder: HTTP ${response.status}`
          );
        }
    
        const json = await response.json();
        const payload = json.data ?? json;
    
        return payload.draft_revision;
      },
      {
        apiUrl: API_URL,
        centerSlug: fixture.centerSlug,
        courseSlug: fixture.courseSlug,
        lessonId: fixture.lessonId,
      }
    );
    
    assert.ok(
      draftAfterReorder,
      "Draft revision must remain available after reload."
    );
    
    const persistedBlockIds = draftAfterReorder.blocks.map(
      (block) => Number(block.id)
    );
    
    assert.notDeepEqual(
      persistedBlockIds,
      originalBlockIds,
      "Persisted order must differ from original order."
    );
    
    assert.deepEqual(
      persistedBlockIds,
      expectedOrderedIds,
      "Persisted order must exactly match the order submitted by the UI."
    );

    const expectedVideoTitleOrder = draftAfterReorder.blocks
      .filter((block) => block.block_type === "media" && externalVideoTitles.has(block.asset?.title))
      .map((block) => block.asset.title);
    assert.deepEqual(
      [...expectedVideoTitleOrder].sort(),
      [youtubeTitle, driveTitle].sort(),
      "Both external videos must persist in the ordered draft."
    );
    const editorAfterReload = await adminPage.locator("main").innerText();
    for (const title of externalVideoTitles) {
      assert.ok(
        editorAfterReload.includes(title),
        `Reloaded editor displays saved video ${title}.`
      );
    }
    
    console.log(
      "✓ Block ordering persisted across reload and matches the canonical API order."
    );
    












    // -----------------------------------------------------------------------
    // ASSERTION 11: Opening Student Preview
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 11] Opening Live Student Preview...");
    const previewTab = adminPage.locator('button:has-text("Live Student Preview")');
    await previewTab.click();
    await adminPage.waitForSelector('h1', { state: "visible", timeout: 10000 });

    const previewH1 = await adminPage.locator("h1").first().innerText();
    assert.ok(previewH1.length > 0, `Preview has proper semantic H1: ${previewH1}`);

    const previewBody = await adminPage.locator("main").innerText();
    assert.ok(
      previewBody.includes("G5.2.1 Rigorous Production Acceptance"),
      "Draft content rendered accurately in Live Student Preview"
    );
    console.log("✓ Live Student Preview verified.");

    // Switch back to editor
    await adminPage.locator('button:has-text("Content Editor")').click();
    await adminPage.waitForSelector('h2:has-text("Content Composer")', { timeout: 5000 });

    // -----------------------------------------------------------------------
    // ASSERTION 12: Viewing Revision History
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 12] Inspecting Revision History...");
    const historySection = adminPage.locator('h2:has-text("Revision History")');
    assert.ok(await historySection.isVisible(), "Revision History section is present");

    // -----------------------------------------------------------------------
    // ASSERTION 14: Publishing Lesson Revision
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 14] Publishing Draft Revision...");
    const publishBtn = adminPage.locator('button:has-text("Publish Version"), button:has-text("Publish revision")').first();
    assert.ok(await publishBtn.isVisible(), "Publish button is visible");

    const publishPromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/publish") && resp.status() === 200
    );
    await publishBtn.click();
    const publishRes = await publishPromise;
    assert.equal(publishRes.status(), 200, "Publish API returned HTTP 200");
    await adminPage.waitForSelector('text=Published', { timeout: 5000 });
    console.log("✓ Revision published. Status displays 'Published'.");

    // -----------------------------------------------------------------------
    // ASSERTION 13: Restoring a Previous Revision as a New Draft
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 13] Restoring Published Revision as a New Draft...");
    const restoreBtn = adminPage.locator('button:has-text("Restore as new draft")').first();
    await restoreBtn.waitFor({ state: "visible" });
    assert.ok(!(await restoreBtn.isDisabled()), "Restore button is enabled now that draft is published");

    const restorePromise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/revisions") && (resp.status() === 201 || resp.status() === 200)
    );
    await restoreBtn.click();
    const restoreRes = await restorePromise;
    assert.ok(restoreRes.status() === 201 || restoreRes.status() === 200, "Restore API returned HTTP 200/201");
    await adminPage.waitForSelector('text=Draft', { timeout: 5000 });
    console.log("✓ Older revision restored as a new draft revision.");

    // Publish this revision as well so students have the final active lesson
    const publishV2Btn = adminPage.locator('button:has-text("Publish Version"), button:has-text("Publish revision")').first();
    await publishV2Btn.waitFor({ state: "visible" });
    const publishV2Promise = adminPage.waitForResponse(
      (resp) => resp.url().includes("/publish") && resp.status() === 200
    );
    await publishV2Btn.click();
    await publishV2Promise;
    console.log("✓ Final revision published for student access.");

    await adminContext.close();

    // -----------------------------------------------------------------------
    // ASSERTION 15: Student Authentication
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 15] Authenticating as Enrolled Student...");
    const studentContext = await browser.newContext();
    const studentPage = await studentContext.newPage();

    await studentPage.route("https://www.youtube-nocookie.com/**", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/html",
        body: "<!doctype html><title>Mock YouTube player</title>",
      })
    );
    await studentPage.route("https://drive.google.com/**", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/html",
        body: "<!doctype html><title>Mock Drive preview</title>",
      })
    );

    await studentPage.goto(`${BASE_URL}/login`, { waitUntil: "networkidle" });
    await studentPage.fill('input[name="email"]', fixture.studentEmail);
    await studentPage.fill('input[name="password"]', fixture.studentPassword);

    const studentLoginPromise = studentPage.waitForResponse(
      (resp) => resp.url().includes("/api/auth/login") && resp.status() === 200
    );
    await studentPage.click('button[type="submit"]');
    const studentLoginRes = await studentLoginPromise;
    assert.equal(studentLoginRes.status(), 200, "Student authentication returned HTTP 200");

    await studentPage.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 10000 });
    console.log("✓ Enrolled student authenticated. Current URL:", studentPage.url());

    // -----------------------------------------------------------------------
    // ASSERTION 16: Mandatory Student Assessment Lifecycle & Media Retrieval
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 16] Student Lesson View, Media Retrieval, and Assessment Lifecycle Verification...");
    const studentLessonUrl = `${BASE_URL}/courses/${fixture.courseSlug}/learn/lessons/${fixture.lessonId}?center=${fixture.centerSlug}`;
    const studentRes = await studentPage.goto(studentLessonUrl, { waitUntil: "networkidle" });
    assert.equal(studentRes.status(), 200, "Student lesson page returned HTTP 200");
    studentPage.on("console", (msg) => {
      if (msg.type() === "error") consoleErrors.push(`[Student Browser Error] ${msg.text()}`);
    });
    studentPage.on("pageerror", (err) => {
      consoleErrors.push(`[Student Page Error] ${err.message}`);
    });
    studentPage.on("response", (resp) => {
      if (resp.status() >= 400 && !resp.url().includes("/favicon.ico")) {
        failedRequests.push({ url: resp.url(), status: resp.status() });
      }
    });

    const studentMain = await studentPage.locator("main").innerText();
    assert.ok(
      studentMain.includes("G5.2.1 Rigorous Production Acceptance"),
      "Student sees the published revision text content!"
    );

    const renderedVideoTitles = (await studentPage.locator("article h3").allInnerTexts())
      .filter((title) => externalVideoTitles.has(title));
    assert.deepEqual(
      renderedVideoTitles,
      expectedVideoTitleOrder,
      "The student sees both external videos in the exact published order."
    );

    for (const title of expectedVideoTitleOrder) {
      const videoHeading = studentPage.locator("article h3").filter({ hasText: title });
      const videoCard = videoHeading.locator("xpath=ancestor::article[1]");
      const loadButton = videoCard.getByRole("button", { name: "Load video" });
      const expectedProvider = title === youtubeTitle ? "youtube" : "google_drive";
      const accessRequest = studentPage.waitForResponse(
        (response) => {
          const requestUrl = new URL(response.url());
          return requestUrl.pathname.endsWith("/access")
            && response.request().method() === "GET";
        },
        { timeout: 15000 }
      );
      await loadButton.click();
      const accessResponse = await accessRequest;
      const accessBody = await accessResponse.text();
      assert.equal(
        accessResponse.status(),
        200,
        `Student video access failed: HTTP ${accessResponse.status()}\n${accessBody}`
      );
      const access = (JSON.parse(accessBody).data ?? JSON.parse(accessBody));
      assert.equal(access.provider, expectedProvider);
      await videoCard.locator(`iframe[title="${title}"]`).waitFor({ state: "visible" });
    }
    console.log("✓ Student sees and can request playback for external videos in published order (provider pages mocked).");

    // 16a. Verify Media Retrieval (Image Asset)
    const imageCard = studentPage.locator("article").filter({
      has: studentPage.locator("h3").filter({ hasText: "E2E Diagram Asset" }),
    });
    const imageAccessPromise = studentPage.waitForResponse(
      (response) => {
        const url = new URL(response.url());
        return url.pathname.endsWith(`/assets/${fixture.imageAssetId}/access`)
          && response.request().method() === "GET";
      },
      { timeout: 15000 }
    );
    const imageFileResponsePromise = studentPage.waitForResponse(
      (response) => new URL(response.url()).pathname.includes("/private-storage/"),
      { timeout: 15000 }
    );
    await imageCard.getByRole("button", { name: "View image" }).click();
    const imageAccessResponse = await imageAccessPromise;
    const imageAccessBody = await imageAccessResponse.text();
    assert.equal(
      imageAccessResponse.status(),
      200,
      `Student image access failed: HTTP ${imageAccessResponse.status()}\n${imageAccessBody}`
    );
    const imageFileResponse = await imageFileResponsePromise;
    assert.equal(
      imageFileResponse.status(),
      200,
      `Student image file failed: HTTP ${imageFileResponse.status()}`
    );
    assert.match(imageFileResponse.headers()["content-type"] ?? "", /^image\/png/);

    const imgElement = studentPage.locator('img[alt="E2E Diagram Asset"]');
    await imgElement.waitFor({ state: "visible", timeout: 10000 });
    await imgElement.scrollIntoViewIfNeeded();
    await imgElement.evaluate((img) => img.decode());
    const imgLoaded = await imgElement.evaluate((img) => img.complete && img.naturalWidth > 0);
    assert.ok(imgLoaded, "Image 'E2E Diagram Asset' is fully loaded in DOM with naturalWidth > 0");
    const imgSrc = await imgElement.getAttribute("src");
    assert.ok(imgSrc && imgSrc.length > 0, "Image asset has valid src attribute in student view");
    console.log("✓ Student media image rendered and verified loaded (naturalWidth > 0).");

    // 16b. Authorized Student PDF Download Verification
    const authorizedPdfDownload = await studentPage.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId, pdfAssetId }) => {
      const res = await fetch(
        `${apiUrl}/api/centers/${centerSlug}/courses/${courseSlug}/learn/lessons/${lessonId}/assets/${pdfAssetId}/download`,
        { credentials: "include" }
      );
      const buffer = await res.arrayBuffer();
      const bytes = new Uint8Array(buffer);
      const header = String.fromCharCode(...bytes.slice(0, 5));
      let binary = "";
      for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
      }
      return {
        status: res.status,
        length: bytes.length,
        header: header,
        base64: btoa(binary),
      };
    }, {
      apiUrl: API_URL,
      centerSlug: fixture.centerSlug,
      courseSlug: fixture.courseSlug,
      lessonId: fixture.lessonId,
      pdfAssetId: fixture.pdfAssetId,
    });
    assert.equal(authorizedPdfDownload.status, 200, "Authorized student PDF download returns HTTP 200");
    assert.equal(authorizedPdfDownload.header, "%PDF-", "Authorized student PDF starts with binary %PDF- header");
    assert.ok(authorizedPdfDownload.length > 100, `Authorized student PDF byte size must be > 100 bytes (got ${authorizedPdfDownload.length})`);

    const pdfDocument = await PDFDocument.load(Buffer.from(authorizedPdfDownload.base64, "base64"));
    assert.ok(pdfDocument.getPageCount() >= 1, "Downloaded PDF must contain at least one valid page");
    console.log(`✓ Authorized student PDF parsed successfully (${pdfDocument.getPageCount()} pages, ${authorizedPdfDownload.length} bytes).`);

    // 16c. Unenrolled User PDF Access Rejection
    const anonContext = await browser.newContext();
    const anonPage = await anonContext.newPage();
    await anonPage.goto(`${BASE_URL}/login`, { waitUntil: "networkidle" });
    const anonPdfDownload = await anonPage.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId, pdfAssetId }) => {
      const res = await fetch(
        `${apiUrl}/api/centers/${centerSlug}/courses/${courseSlug}/learn/lessons/${lessonId}/assets/${pdfAssetId}/download`
      );
      return { status: res.status };
    }, {
      apiUrl: API_URL,
      centerSlug: fixture.centerSlug,
      courseSlug: fixture.courseSlug,
      lessonId: fixture.lessonId,
      pdfAssetId: fixture.pdfAssetId,
    });
    await anonContext.close();
    assert.ok(
      anonPdfDownload.status === 401 || anonPdfDownload.status === 403 || anonPdfDownload.status === 404,
      `Unenrolled/unauthenticated PDF download must be rejected (got ${anonPdfDownload.status})`
    );
    console.log("✓ Unenrolled/unauthenticated PDF access securely rejected.");

    // 16d. Mandatory Assessment Lifecycle Flow (No optional visibility branches)
    assert.ok(authoredExercise?.id > 0, "Authored exercise must be present from Assertion 9a.");
    console.log(`Executing mandatory assessment lifecycle for authored exercise ${authoredExercise.id} ("${authoredExercise.title}")...`);

    const authoredBlock = studentPage.locator("article").filter({ hasText: authoredExercise.title });
    await authoredBlock.waitFor({ state: "visible", timeout: 15000 });

    const startAssessmentBtn = authoredBlock.locator('button:has-text("Start Assessment")');
    await startAssessmentBtn.waitFor({ state: "visible", timeout: 10000 });

    const startAttemptPromise = studentPage.waitForResponse(
      (resp) => {
        const url = new URL(resp.url());
        return (
          resp.request().method() === "POST" &&
          url.pathname.endsWith(`/exercises/${authoredExercise.id}/attempts`) &&
          (resp.status() === 201 || resp.status() === 200)
        );
      },
      { timeout: 15000 }
    );
    await startAssessmentBtn.click();
    const startAttemptRes = await startAttemptPromise;
    assert.ok(
      startAttemptRes.status() === 200 || startAttemptRes.status() === 201,
      "Start attempt API returned success"
    );
    const startJson = await startAttemptRes.json();
    const startPayload = startJson.data ?? startJson;
    const attemptId = Number(startPayload.id);
    assert.ok(attemptId > 0, "Created attempt has valid ID.");
    console.log(`✓ Assessment attempt #${startPayload.attempt_number} (ID: ${attemptId}) started.`);

    // Phase E2.2-C: Student response payload privacy verification
    assert.equal(startPayload.show_correct_answers, false, "Pre-submission payload must not show correct answers.");
    for (const q of startPayload.questions) {
      assert.equal(q.is_correct, undefined, `Pre-submission question ${q.id} must not leak is_correct.`);
      assert.equal(q.points_awarded, undefined, `Pre-submission question ${q.id} must not leak points_awarded.`);
      assert.equal(q.correct_option_id, undefined, `Pre-submission question ${q.id} must not leak correct_option_id.`);
      assert.equal(q.correct_option_ids, undefined, `Pre-submission question ${q.id} must not leak correct_option_ids.`);
      assert.equal(q.accepted_answers, undefined, `Pre-submission question ${q.id} must not leak accepted_answers.`);
      assert.equal(q.answer_config_snapshot, undefined, `Pre-submission question ${q.id} must not leak answer_config_snapshot.`);
      assert.equal(q.explanation, undefined, `Pre-submission question ${q.id} must not leak explanation.`);
      if (Array.isArray(q.options)) {
        for (const opt of q.options) {
          assert.equal(opt.is_correct, undefined, `Pre-submission option ${opt.id} must not leak is_correct.`);
        }
      }
    }
    console.log("✓ Pre-submission answer privacy verified across all questions and options.");

    // Extract questions and correct options from authoredExercise
    const q1 = authoredExercise.questions.find((q) => q.question_type === "single_choice");
    const salutOption = q1.options.find((opt) => opt.option_text === "Salut");
    const goodbyeOption = q1.options.find((opt) => opt.option_text === "Goodbye");
    assert.ok(salutOption?.id, "Salut option found.");
    assert.ok(goodbyeOption?.id, "Goodbye option found.");

    const q2 = authoredExercise.questions.find((q) => q.question_type === "true_false");
    const trueOption = q2.options.find((opt) => opt.option_text === "True");
    assert.ok(trueOption?.id, "True option found.");

    const q3 = authoredExercise.questions.find((q) => q.question_type === "multiple_select");
    const greenOption = q3.options.find((opt) => opt.option_text === "Green");
    const orangeOption = q3.options.find((opt) => opt.option_text === "Orange");
    const whiteOption = q3.options.find((opt) => opt.option_text === "White");
    assert.ok(greenOption?.id, "Green option found.");
    assert.ok(orangeOption?.id, "Orange option found.");
    assert.ok(whiteOption?.id, "White option found.");

    const q4 = authoredExercise.questions.find((q) => q.question_type === "short_answer");
    assert.ok(q4?.id, "Short answer question found.");

    // Select answers across all four question types while testing navigation:
    // Question 1: Single Choice ("Salut")
    const salutInput = authoredBlock.locator(`input[data-option-id="${salutOption.id}"]`);
    await salutInput.waitFor({ state: "visible", timeout: 10000 });
    await salutInput.check();
    assert.equal(await salutInput.isChecked(), true, "Question 1 Salut selected.");

    // Navigate to Question 2
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    const trueInput = authoredBlock.locator(`input[data-option-id="${trueOption.id}"]`);
    await trueInput.waitFor({ state: "visible", timeout: 10000 });
    await trueInput.check();
    assert.equal(await trueInput.isChecked(), true, "Question 2 True selected.");

    // Navigate to Question 3
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    const greenInput = authoredBlock.locator(`input[data-option-id="${greenOption.id}"]`);
    const orangeInput = authoredBlock.locator(`input[data-option-id="${orangeOption.id}"]`);
    const whiteInput = authoredBlock.locator(`input[data-option-id="${whiteOption.id}"]`);
    await greenInput.waitFor({ state: "visible", timeout: 10000 });
    await greenInput.check();
    await orangeInput.check();
    assert.equal(await greenInput.isChecked(), true, "Question 3 Green selected.");
    assert.equal(await orangeInput.isChecked(), true, "Question 3 Orange selected.");
    assert.equal(await whiteInput.isChecked(), false, "Question 3 White remains unselected.");

    // Navigate to Question 4
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    const shortAnswerInput = authoredBlock.locator(`#attempt-question-${q4.id}`).locator('input[type="text"]');
    await shortAnswerInput.waitFor({ state: "visible", timeout: 10000 });
    await shortAnswerInput.fill("BONJOUR");
    assert.equal(await shortAnswerInput.inputValue(), "BONJOUR", "Question 4 filled with uppercase BONJOUR.");

    // Backward navigation state preservation test
    await authoredBlock.getByRole("button", { name: "Previous question" }).click();
    assert.equal(await greenInput.isChecked(), true, "Navigation backward preserves Q3 Green.");
    assert.equal(await orangeInput.isChecked(), true, "Navigation backward preserves Q3 Orange.");
    await authoredBlock.getByRole("button", { name: "Previous question" }).click();
    assert.equal(await trueInput.isChecked(), true, "Navigation backward preserves Q2 True.");
    await authoredBlock.getByRole("button", { name: "Previous question" }).click();
    assert.equal(await salutInput.isChecked(), true, "Navigation backward preserves Q1 Salut.");

    // Navigate back to Q4
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    await authoredBlock.getByRole("button", { name: "Next question" }).click();
    assert.equal(await shortAnswerInput.inputValue(), "BONJOUR", "Navigation forward preserves Q4 text.");
    console.log("✓ Question navigation state preservation verified.");

    // Save progress
    const saveProgressButton = authoredBlock.locator('button:has-text("Save Progress")');
    await saveProgressButton.waitFor({ state: "visible", timeout: 10000 });

    const saveProgressPromise = studentPage.waitForResponse(
      (response) => {
        const pathname = new URL(response.url()).pathname;
        return (
          response.request().method() === "PATCH" &&
          pathname.endsWith(`/exercises/${authoredExercise.id}/attempts/current`) &&
          response.status() === 200
        );
      },
      { timeout: 15000 }
    );
    await saveProgressButton.click();
    const saveResponse = await saveProgressPromise;
    assert.equal(saveResponse.status(), 200, "Save progress returns HTTP 200.");
    const saveJson = await saveResponse.json();
    const savePayload = saveJson.data ?? saveJson;

    // Privacy check on saved response
    for (const question of savePayload.questions) {
      assert.equal(question.is_correct, undefined, "Save progress response must not leak is_correct.");
      assert.equal(question.correct_option_ids, undefined, "Save progress response must not leak correct_option_ids.");
      assert.equal(question.accepted_answers, undefined, "Save progress response must not leak accepted_answers.");
    }

    const savedQ1 = savePayload.questions.find((q) => Number(q.id) === Number(q1.id));
    assert.equal(Number(savedQ1.selected_option_id), Number(salutOption.id), "Saved Q1 option matches.");
    const savedQ2 = savePayload.questions.find((q) => Number(q.id) === Number(q2.id));
    assert.equal(Number(savedQ2.selected_option_id), Number(trueOption.id), "Saved Q2 option matches.");
    const savedQ3 = savePayload.questions.find((q) => Number(q.id) === Number(q3.id));
    assert.deepEqual(
      (savedQ3.selected_option_ids || []).map(Number).sort(),
      [Number(greenOption.id), Number(orangeOption.id)].sort(),
      "Saved Q3 multi-select options match."
    );
    const savedQ4 = savePayload.questions.find((q) => Number(q.id) === Number(q4.id));
    assert.equal(savedQ4.text_answer, "BONJOUR", "Saved Q4 text answer matches.");
    console.log("✓ Progress saved to backend and response privacy validated.");

    // Reload page to prove persistence from database across browser refresh
    await studentPage.reload({ waitUntil: "networkidle" });
    const reloadedBlock = studentPage.locator("article").filter({ hasText: authoredExercise.title });
    await reloadedBlock.waitFor({ state: "visible", timeout: 15000 });

    await studentPage.waitForFunction(
      ({ opt1, opt2, opt3a, opt3b }) => {
        const el1 = document.querySelector(`input[data-option-id="${opt1}"]`);
        const el2 = document.querySelector(`input[data-option-id="${opt2}"]`);
        const el3a = document.querySelector(`input[data-option-id="${opt3a}"]`);
        const el3b = document.querySelector(`input[data-option-id="${opt3b}"]`);
        return el1?.checked === true && el2?.checked === true && el3a?.checked === true && el3b?.checked === true;
      },
      {
        opt1: salutOption.id,
        opt2: trueOption.id,
        opt3a: greenOption.id,
        opt3b: orangeOption.id,
      },
      { timeout: 15000 }
    );

    const reloadedTextInput = reloadedBlock.locator(`#attempt-question-${q4.id}`).locator('input[type="text"]');
    assert.equal(await reloadedTextInput.inputValue(), "BONJOUR", "Text answer BONJOUR restored from server after reload.");
    console.log("✓ All 4 answers restored accurately from database after page reload.");

    // Submit attempt
    const submitButton = reloadedBlock.locator('button:has-text("Submit Assessment")');
    await submitButton.waitFor({ state: "visible", timeout: 10000 });

    const submitPromise = studentPage.waitForResponse(
      (response) => {
        const pathname = new URL(response.url()).pathname;
        return (
          response.request().method() === "POST" &&
          pathname.endsWith(`/exercises/${authoredExercise.id}/attempts/${attemptId}/submit`) &&
          response.status() === 200
        );
      },
      { timeout: 15000 }
    );

    await submitButton.click();
    const submitResponse = await submitPromise;
    assert.equal(submitResponse.status(), 200, "Assessment submission must return HTTP 200.");
    const submittedJson = await submitResponse.json();
    const submittedAttempt = submittedJson.data ?? submittedJson;
    assert.equal(submittedAttempt.passed, true, "All four correct answers must pass the assessment.");
    assert.equal(Number(submittedAttempt.percentage), 100, "All four correct answers must score 100%.");
    console.log("✓ Attempt submitted and graded at 100% (Passed).");

    // Idempotent duplicate submission verification
    const duplicateSubmitProbe = await studentPage.evaluate(
      async ({ apiUrl, centerSlug, courseSlug, lessonId, exerciseId, attemptId }) => {
        const res = await fetch(
          `${apiUrl}/api/centers/${centerSlug}/student/courses/${courseSlug}/lessons/${lessonId}/exercises/${exerciseId}/attempts/${attemptId}/submit`,
          {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            credentials: "include",
            body: JSON.stringify({ answers: [] }),
          }
        );
        const data = await res.json();
        return { status: res.status, data };
      },
      {
        apiUrl: API_URL,
        centerSlug: fixture.centerSlug,
        courseSlug: fixture.courseSlug,
        lessonId: fixture.lessonId,
        exerciseId: authoredExercise.id,
        attemptId,
      }
    );
    assert.equal(duplicateSubmitProbe.status, 200, "Duplicate submission of graded attempt returns HTTP 200 (idempotent).");
    assert.equal((duplicateSubmitProbe.data.data ?? duplicateSubmitProbe.data).passed, true);
    console.log("✓ Duplicate submission is idempotent (HTTP 200).");

    // Altered submission payload verification (HTTP 409 Conflict)
    const conflictingSubmitProbe = await studentPage.evaluate(
      async ({ apiUrl, centerSlug, courseSlug, lessonId, exerciseId, attemptId, wrongOptionId, q1Id }) => {
        const res = await fetch(
          `${apiUrl}/api/centers/${centerSlug}/student/courses/${courseSlug}/lessons/${lessonId}/exercises/${exerciseId}/attempts/${attemptId}/submit`,
          {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            credentials: "include",
            body: JSON.stringify({
              answers: [
                { question_id: q1Id, selected_option_id: wrongOptionId },
              ],
            }),
          }
        );
        return { status: res.status };
      },
      {
        apiUrl: API_URL,
        centerSlug: fixture.centerSlug,
        courseSlug: fixture.courseSlug,
        lessonId: fixture.lessonId,
        exerciseId: authoredExercise.id,
        attemptId,
        wrongOptionId: goodbyeOption.id,
        q1Id: q1.id,
      }
    );
    assert.equal(conflictingSubmitProbe.status, 409, "Submitting altered answers to already completed attempt returns HTTP 409 Conflict.");
    console.log("✓ Altered submission to graded attempt safely rejected with HTTP 409 Conflict.");

    // Verify result display after reload
    await studentPage.reload({ waitUntil: "networkidle" });
    const completedBlock = studentPage.locator("article").filter({ hasText: authoredExercise.title });
    await completedBlock.waitFor({ state: "visible", timeout: 15000 });
    await completedBlock.getByText("Passed", { exact: true }).first().waitFor({
      state: "visible",
      timeout: 15000,
    });

    const resultText = await completedBlock.innerText();
    assert.ok(
      resultText.includes("100%"),
      "Completed assessment display must include 100% score."
    );

    // History verification
    const historyButton = completedBlock.locator('button:has-text("View Attempt History")');
    await historyButton.waitFor({ state: "visible", timeout: 10000 });
    await historyButton.click();

    const historyEntry = completedBlock.getByText(/Attempt\s*#?\s*1/);
    await historyEntry.first().waitFor({ state: "visible", timeout: 10000 });
    assert.ok(await historyEntry.first().isVisible(), "Completed Attempt #1 must appear in history.");

    console.log(
      "✓ Assessment lifecycle fully verified across all 4 question types: Start, Privacy, Navigate, Save, Reload, Submit, Idempotency, Conflict-rejection, Result, and History."
    );
    // -----------------------------------------------------------------------
    // ASSERTION 17: Administrative Controls are Inaccessible to Students
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 17] Verifying Administrative Controls & API Boundary...");
    // 1. In DOM
    const adminControls = studentPage.locator(
      'button:has-text("+ Add Text"), button:has-text("+ Add Media"), button:has-text("Publish Version"), button:has-text("Edit Metadata")'
    );
    assert.equal(await adminControls.count(), 0, "No administrative controls present in student DOM");

    // 2. Direct API call from student session to admin endpoint must return 403 Forbidden
    const adminApiProbe = await studentPage.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId }) => {
      const res = await fetch(
        `${apiUrl}/api/centers/${centerSlug}/admin/courses/${courseSlug}/lessons/${lessonId}/content`,
        {
          credentials: "include",
          headers: { Accept: "application/json" },
        }
      );
      return { status: res.status };
    }, { apiUrl: API_URL, centerSlug: fixture.centerSlug, courseSlug: fixture.courseSlug, lessonId: fixture.lessonId });

    assert.equal(adminApiProbe.status, 403, "Direct student request to Admin API returned 403 Forbidden");
    console.log("✓ Administrative controls and endpoints securely rejected student access with 403.");

    await studentContext.close();

    console.log("\n=================================================");
    console.log("ALL 17 PRODUCTION BROWSER ACCEPTANCE WORKFLOWS PASSED!");
    console.log("=================================================");
  } catch (err) {
    primaryError = err;
  } finally {
    const cleanupErrors = [];

    if (browser) {
      try {
        await browser.close();
      } catch (cleanupError) {
        cleanupErrors.push(cleanupError);
      }
    }

    if (fixture) {
      try {
        teardownFixture(fixture.centerId);
      } catch (cleanupError) {
        cleanupErrors.push(cleanupError);
      }
    }

    if (primaryError && cleanupErrors.length > 0) {
      throw new AggregateError(
        [primaryError, ...cleanupErrors],
        "Browser acceptance failed and cleanup also reported errors."
      );
    }

    if (primaryError) {
      throw primaryError;
    }

    if (cleanupErrors.length === 1) {
      throw cleanupErrors[0];
    }

    if (cleanupErrors.length > 1) {
      throw new AggregateError(
        cleanupErrors,
        "Browser acceptance cleanup reported multiple errors."
      );
    }
  }

  // Strictly assert no unexpected failed requests or critical errors occurred
  const unexpectedFailedRequests = failedRequests.filter((r) => {
    const failureKey = `${r.status}:${new URL(r.url).pathname}`;
    if (intentionalAcceptanceFailures.has(failureKey)) return false;
    // Exclude deliberate student admin probe which must return 403
    if (r.status === 403 && r.url.includes("/admin/courses/")) return false;
    // Exclude deliberate deceptive-host validation which must return 422.
    if (r.status === 422 && r.url.endsWith("/assets/external-video")) return false;
    // Exclude favicon
    if (r.url.includes("/favicon.ico")) return false;
    return true;
  });

  const criticalConsoleErrors = consoleErrors.filter((e) => {
    if (e.includes("favicon")) return false;
    if (e.includes("Failed to load resource") && e.includes("403")) return false;
    if (e.includes("Failed to load resource") && e.includes("422 (Unprocessable Content)")) return false;
    return true;
  });

  assert.equal(
    unexpectedFailedRequests.length,
    0,
    `Unexpected failed HTTP requests: ${JSON.stringify(unexpectedFailedRequests)}`
  );
  assert.equal(
    criticalConsoleErrors.length,
    0,
    `Unexpected console/page errors: ${JSON.stringify(criticalConsoleErrors)}`
  );
}

main().catch((err) => {
  console.error("\n❌ Browser Acceptance Failed:", err);
  process.exit(1);
});
