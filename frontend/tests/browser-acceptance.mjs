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
    console.log("✓ Exercise block attached via API.");

    
    // -----------------------------------------------------------------------
    // ASSERTION 10: Reordering Blocks via UI and Verifying Persisted Order
    // -----------------------------------------------------------------------
    
    console.log(
      "\n[Assertion 10] Testing Block Reordering in Timeline via Accessible UI..."
    );
    
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
    
    // Perform actual UI keyboard operation.
    await adminPage.keyboard.press("Space");
    await adminPage.keyboard.press("ArrowDown");
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
    
    console.log(
      "✓ Block ordering persisted across reload and matches the canonical API order."
    );
    












    // -----------------------------------------------------------------------
    // ASSERTION 11: Opening Student Preview
    // -----------------------------------------------------------------------
    console.log("\n[Assertion 11] Opening Live Student Preview...");
    const previewTab = adminPage.locator('button:has-text("Live Student Preview")');
    await previewTab.click();
    await adminPage.waitForSelector('main h1', { timeout: 5000 });

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

    const studentMain = await studentPage.locator("main").innerText();
    assert.ok(
      studentMain.includes("G5.2.1 Rigorous Production Acceptance"),
      "Student sees the published revision text content!"
    );

    // 16a. Verify Media Retrieval (Image Asset)
    const imgElement = studentPage.locator('img[alt="E2E Diagram Asset"]');
    await imgElement.waitFor({ state: "visible", timeout: 10000 });
    const imgLoaded = await imgElement.evaluate((img) => img.complete && img.naturalWidth > 0);
    assert.ok(imgLoaded, "Image 'E2E Diagram Asset' is fully loaded in DOM with naturalWidth > 0");
    const imgSrc = await imgElement.getAttribute("src");
    assert.ok(imgSrc && imgSrc.length > 0, "Image asset has valid src attribute in student view");
    console.log("✓ Student media image rendered and verified loaded (naturalWidth > 0).");

    // 16b. Authorized Student PDF Download Verification
    const authorizedPdfDownload = await studentPage.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId, pdfAssetId }) => {
      const res = await fetch(
        `${apiUrl}/api/centers/${centerSlug}/student/courses/${courseSlug}/lessons/${lessonId}/assets/${pdfAssetId}/download`,
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
    const anonPdfDownload = await anonPage.evaluate(async ({ apiUrl, centerSlug, courseSlug, lessonId, pdfAssetId }) => {
      const res = await fetch(
        `${apiUrl}/api/centers/${centerSlug}/student/courses/${courseSlug}/lessons/${lessonId}/assets/${pdfAssetId}/download`
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
    console.log("Executing mandatory assessment attempt lifecycle...");
    const startAssessmentBtn = studentPage.locator('button:has-text("Start Assessment")');
    await startAssessmentBtn.waitFor({ state: "visible", timeout: 10000 });

    const startAttemptPromise = studentPage.waitForResponse(
      (resp) => resp.url().includes("/attempts") && (resp.status() === 201 || resp.status() === 200)
    );
    await startAssessmentBtn.click();
    const startAttemptRes = await startAttemptPromise;
    assert.ok(startAttemptRes.status() === 200 || startAttemptRes.status() === 201, "Start attempt API returned success");
    console.log("✓ Assessment attempt started.");

    // -----------------------------------------------------------------------
    // Assessment lifecycle: Start -> Select -> Save -> Reload -> Resume
    // -> Submit -> Result -> History
    // -----------------------------------------------------------------------

    const expectedOptionId = String(
      fixture.correctOptionId
    );

    assert.ok(
      expectedOptionId !== "undefined" &&
      expectedOptionId !== "null",
      "Fixture must provide correctOptionId."
    );

    // 1. Select correct answer.
    const correctOptionButton = studentPage.locator(
      `button[data-option-id="${expectedOptionId}"]`
    );

    await correctOptionButton.waitFor({
      state: "visible",
      timeout: 10000,
    });

    await correctOptionButton.click();

    assert.equal(
      await correctOptionButton.getAttribute("aria-pressed"),
      "true",
      "Selected answer must be marked as selected."
    );

    // 2. Save progress.
    const saveProgressButton = studentPage.locator(
      'button:has-text("Save Progress")'
    );

    await saveProgressButton.waitFor({
      state: "visible",
      timeout: 10000,
    });

    const saveProgressPromise = studentPage.waitForResponse(
      (response) => {
        const pathname = new URL(response.url()).pathname;

        return (
          response.request().method() === "PATCH" &&
          pathname.endsWith(
            `/exercises/${fixture.exerciseId}/attempts/current`
          ) &&
          response.status() === 200
        );
      },
      { timeout: 15000 }
    );

    await saveProgressButton.click();

    const saveResponse = await saveProgressPromise;

    assert.equal(
      saveResponse.status(),
      200
    );

    const saveJson = await saveResponse.json();

    const savePayload = saveJson.data ?? saveJson;

    assert.ok(
      Array.isArray(savePayload.answers) &&
      savePayload.answers.length > 0,
      "Backend must return saved attempt answers."
    );

    // 3. Reload the actual browser page.
    // This is required to prove persistence, not local React state.
    await studentPage.reload({
      waitUntil: "networkidle",
    });

    // 4. Wait until the same answer is restored.
    const restoredOptionButton = studentPage.locator(
      `button[data-option-id="${expectedOptionId}"]`
    );

    await restoredOptionButton.waitFor({
      state: "visible",
      timeout: 15000,
    });

    await studentPage.waitForFunction(
      (optionId) => {
        const button = document.querySelector(
          `button[data-option-id="${optionId}"]`
        );

        return button?.getAttribute("aria-pressed") === "true";
      },
      expectedOptionId,
      {
        timeout: 15000,
      }
    );

    assert.equal(
      await restoredOptionButton.getAttribute("aria-pressed"),
      "true",
      "The same answer must be restored from the persisted attempt after reload."
    );

    // 5. Submit attempt.
    const submitButton = studentPage.locator(
      'button:has-text("Submit Assessment")'
    );

    await submitButton.waitFor({
      state: "visible",
      timeout: 10000,
    });

    const submitPromise = studentPage.waitForResponse(
      (response) => {
        const pathname = new URL(response.url()).pathname;

        return (
          response.request().method() === "POST" &&
          pathname.endsWith("/attempts/current/submit") &&
          response.status() === 200
        );
      },
      { timeout: 15000 }
    );

    await submitButton.click();

    const submitResponse = await submitPromise;

    assert.equal(
      submitResponse.status(),
      200,
      "Assessment submission must return HTTP 200."
    );

    // 6. Verify result.
    await studentPage.waitForSelector(
      'text=Passed, text=PASSED, text=Assessment Passed',
      { timeout: 15000 }
    );

    const resultText = await studentPage.locator("main").innerText();

    assert.ok(
      resultText.includes("100%") ||
      resultText.includes("10 / 10"),
      "Correct answer must produce the expected 100% result."
    );

    // 7. Attempt History must be accessible and functional.
    const historyButton = studentPage.locator(
      'button:has-text("View Attempt History")'
    );

    await historyButton.waitFor({
      state: "visible",
      timeout: 10000,
    });

    await historyButton.click();

    const historyEntry = studentPage.getByText(
      /Attempt\s*#?\s*1/
    );

    await historyEntry.waitFor({
      state: "visible",
      timeout: 10000,
    });

    assert.ok(
      await historyEntry.isVisible(),
      "Completed Attempt #1 must appear in history."
    );

    console.log(
      "✓ Assessment lifecycle fully verified: Save, Reload, Resume, Submit, Result and History."
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
    // Exclude deliberate student admin probe which must return 403
    if (r.status === 403 && r.url.includes("/admin/courses/")) return false;
    // Exclude favicon
    if (r.url.includes("/favicon.ico")) return false;
    return true;
  });

  const criticalConsoleErrors = consoleErrors.filter((e) => {
    if (e.includes("favicon")) return false;
    if (e.includes("Failed to load resource") && e.includes("403")) return false;
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
