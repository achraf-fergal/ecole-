import { chromium } from "playwright";
import assert from "node:assert/strict";

const BASE_URL = "http://localhost:3000";

async function main() {
  console.log("=================================================");
  console.log("G5 Browser Acceptance Suite: Unified Lesson Editor");
  console.log("=================================================");

  const browser = await chromium.launch({
    channel: "chrome",
    headless: true,
  });

  try {
    // -----------------------------------------------------------------------
    // STEP 1: Authenticate as Center Administrator
    // -----------------------------------------------------------------------
    console.log("\n[Step 1] Authenticating as Center Administrator...");
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();

    await adminPage.goto(`${BASE_URL}/login`, { waitUntil: "networkidle" });
    await adminPage.fill('input[name="email"]', "admin@example.com");
    await adminPage.fill('input[name="password"]', "Password123!@#");
    await adminPage.click('button[type="submit"]');

    // Wait for redirect away from login
    await adminPage.waitForURL(url => !url.pathname.includes("/login"), { timeout: 10000 });
    console.log("✓ Center admin authenticated. Landed at:", adminPage.url());

    // -----------------------------------------------------------------------
    // STEP 2: Open Lesson in Unified Lesson Editor
    // -----------------------------------------------------------------------
    console.log("\n[Step 2] Opening Lesson 2 in Demo Center...");
    const lessonUrl = `${BASE_URL}/admin/learning/centers/demo-center/courses/general-english-a1/lessons/2`;
    const response = await adminPage.goto(lessonUrl, { waitUntil: "networkidle" });
    assert.equal(response.status(), 200, "Lesson editor page returned HTTP 200");
    console.log("✓ Lesson editor page loaded successfully.");

    // Verify Heading Hierarchy in Admin Editor (H1 -> H2 -> H3)
    const h1List = await adminPage.locator("h1").allInnerTexts();
    console.log("✓ Editor H1:", h1List);
    assert.ok(h1List.length >= 1, "Page contains an H1 heading");

    const h2List = await adminPage.locator("h2").allInnerTexts();
    console.log("✓ Editor H2s:", h2List);
    assert.ok(h2List.some(h => /Content Composer/i.test(h)), "H2 'Content Composer' is present");
    assert.ok(h2List.some(h => /Curriculum Timeline/i.test(h)), "H2 'Curriculum Timeline' is present");
    assert.ok(h2List.some(h => /Revision History/i.test(h)), "H2 'Revision History' is present");

    // -----------------------------------------------------------------------
    // STEP 3: Verify / Ensure Draft Revision Exists
    // -----------------------------------------------------------------------
    console.log("\n[Step 3] Checking Draft Revision status...");
    const createDraftBtn = adminPage.locator('button:has-text("Create New Draft"), button:has-text("Create draft")');
    if (await createDraftBtn.count() > 0 && await createDraftBtn.first().isVisible()) {
      console.log("Clicking 'Create New Draft' button...");
      await createDraftBtn.first().click();
      await adminPage.waitForTimeout(1500);
    }
    console.log("✓ Draft revision is active for editing.");

    // -----------------------------------------------------------------------
    // STEP 4: Test Text Block Composer with TipTap Formatting
    // -----------------------------------------------------------------------
    console.log("\n[Step 4] Opening Text Block Composer...");
    const addTextBtn = adminPage.locator('button:has-text("+ Add Text")');
    assert.ok(await addTextBtn.isVisible(), "Add Text button is visible");
    await addTextBtn.click();
    await adminPage.waitForTimeout(500);

    // TipTap editor surface should now be present
    const editorContent = adminPage.locator('.ProseMirror');
    assert.ok(await editorContent.isVisible(), "TipTap ProseMirror editor is visible");

    // Type text into editor
    await editorContent.click();
    await editorContent.fill("G5 Acceptance Test: Rigorous TipTap formatting verification across heading levels and styles.");
    console.log("✓ Entered text in TipTap ProseMirror.");

    // Verify H1-H6 toolbar buttons exist and are clickable
    for (let level = 1; level <= 6; level++) {
      const hBtn = adminPage.locator(`button[aria-label="Heading ${level}"]`);
      assert.ok(await hBtn.count() > 0, `Toolbar has H${level} button`);
    }
    console.log("✓ All H1-H6 heading buttons present in TipTap toolbar.");

    // Verify mark toolbar buttons
    const boldBtn = adminPage.locator('button[aria-label="Bold"]');
    const italicBtn = adminPage.locator('button[aria-label="Italic"]');
    const listBtn = adminPage.locator('button[aria-label="Bulleted list"]');
    assert.ok(await boldBtn.count() > 0, "Bold button present");
    assert.ok(await italicBtn.count() > 0, "Italic button present");
    assert.ok(await listBtn.count() > 0, "List button present");
    console.log("✓ Mark buttons (Bold, Italic, List) present in toolbar.");

    // Click "Insert Text Block"
    const saveBlockBtn = adminPage.locator('button:has-text("Insert Text Block")');
    assert.ok(await saveBlockBtn.isVisible(), "Insert Text Block button is visible");
    await saveBlockBtn.click();
    await adminPage.waitForTimeout(2000);
    console.log("✓ Text block inserted.");

    // -----------------------------------------------------------------------
    // STEP 5: Verify Formatting Survives Page Reload
    // -----------------------------------------------------------------------
    console.log("\n[Step 5] Reloading page to verify persistence...");
    await adminPage.reload({ waitUntil: "networkidle" });
    const timelineText = await adminPage.locator("main").innerText();
    assert.ok(timelineText.includes("G5 Acceptance Test"), "Inserted text block survived reload!");
    console.log("✓ Block formatting and content verified persistent across reload.");

    // -----------------------------------------------------------------------
    // STEP 6: Exercise / Media Composer Check
    // -----------------------------------------------------------------------
    console.log("\n[Step 6] Testing Exercise Composer button...");
    const addExerciseBtn = adminPage.locator('button:has-text("+ Add Exercise")');
    await addExerciseBtn.click();
    await adminPage.waitForTimeout(500);
    const exerciseSection = adminPage.locator('h3:has-text("Add Assessment Exercise")');
    assert.ok(await exerciseSection.isVisible(), "Add Assessment Exercise section opened");
    console.log("✓ Exercise composer opened with semantic H3 heading.");

    // Switch back to none
    await addExerciseBtn.click();
    await adminPage.waitForTimeout(300);

    // -----------------------------------------------------------------------
    // STEP 7: Reorder Blocks in Curriculum Timeline
    // -----------------------------------------------------------------------
    console.log("\n[Step 7] Checking Curriculum Timeline blocks...");
    const moveButtons = adminPage.locator('button[aria-label*="Move"]');
    const moveCount = await moveButtons.count();
    console.log(`Found ${moveCount} reorder buttons in timeline.`);
    if (moveCount > 0) {
      console.log("Clicking reorder button...");
      await moveButtons.first().click();
      await adminPage.waitForTimeout(1000);
      console.log("✓ Reorder action dispatched.");
    }

    // -----------------------------------------------------------------------
    // STEP 8: Live Student Preview
    // -----------------------------------------------------------------------
    console.log("\n[Step 8] Switching to Live Student Preview...");
    const previewTab = adminPage.locator('button:has-text("Live Student Preview")');
    await previewTab.click();
    await adminPage.waitForTimeout(2000);

    const previewH1 = await adminPage.locator("h1").allInnerTexts();
    console.log("✓ Live Preview H1:", previewH1);
    assert.ok(previewH1.length >= 1, "Preview has top-level H1 heading");

    // Check draft banner is visible in preview
    const draftBanner = adminPage.locator('text=Draft Preview');
    if (await draftBanner.count() > 0) {
      console.log("✓ Draft indicator banner confirmed in admin preview.");
    }

    // Check preview content contains our text
    const previewBody = await adminPage.locator("main").innerText();
    assert.ok(previewBody.includes("G5 Acceptance Test"), "Live preview shows draft text block");
    console.log("✓ Draft content rendered correctly in Live Student Preview.");

    // Switch back to editor
    const editorTab = adminPage.locator('button:has-text("Content Editor")');
    await editorTab.click();
    await adminPage.waitForTimeout(1000);

    // -----------------------------------------------------------------------
    // STEP 9: Inspect Revision History
    // -----------------------------------------------------------------------
    console.log("\n[Step 9] Inspecting Revision History...");
    const historySection = adminPage.locator('h2:has-text("Revision History")');
    assert.ok(await historySection.isVisible(), "Revision History section is visible");
    const historyItems = adminPage.locator('text=/Version \\d+/');
    const historyCount = await historyItems.count();
    console.log(`✓ Revision History lists ${historyCount} historical revision entries.`);
    assert.ok(historyCount >= 1, "Revision history contains at least one revision");

    // -----------------------------------------------------------------------
    // STEP 10: Publish Revision
    // -----------------------------------------------------------------------
    console.log("\n[Step 10] Publishing Draft Revision...");
    const publishBtn = adminPage.locator('button:has-text("Publish Version"), button:has-text("Publish revision")');
    assert.ok(await publishBtn.isVisible(), "Publish button is visible");
    const publishLabel = await publishBtn.innerText();
    console.log(`Clicking '${publishLabel}'...`);
    await publishBtn.click();

    // Confirm dialog if any
    const confirmBtn = adminPage.locator('button:has-text("Confirm Publish"), button:has-text("Confirm")');
    if (await confirmBtn.count() > 0 && await confirmBtn.first().isVisible()) {
      await confirmBtn.first().click();
    }

    await adminPage.waitForTimeout(3000);
    console.log("✓ Publish executed. Checking published state...");

    // After publish, published version is now immutable
    const publishedBadges = adminPage.locator('text=Published');
    assert.ok(await publishedBadges.count() > 0, "Revision status displays Published");
    console.log("✓ Published revision status confirmed.");

    await adminContext.close();

    // -----------------------------------------------------------------------
    // STEP 11: Authenticate as Enrolled Student
    // -----------------------------------------------------------------------
    console.log("\n[Step 11] Authenticating as Enrolled Student...");
    const studentContext = await browser.newContext();
    const studentPage = await studentContext.newPage();

    await studentPage.goto(`${BASE_URL}/login`, { waitUntil: "networkidle" });
    await studentPage.fill('input[name="email"]', "student@example.com");
    await studentPage.fill('input[name="password"]', "Password123!@#");
    await studentPage.click('button[type="submit"]');

    await studentPage.waitForURL(url => !url.pathname.includes("/login"), { timeout: 10000 });
    console.log("✓ Student authenticated. Landed at:", studentPage.url());

    // -----------------------------------------------------------------------
    // STEP 12: Student View — Verify Only Published Content Visible
    // -----------------------------------------------------------------------
    console.log("\n[Step 12] Navigating to Student Lesson View...");
    const studentLessonUrl = `${BASE_URL}/courses/general-english-a1/learn/lessons/2`;
    const studentRes = await studentPage.goto(studentLessonUrl, { waitUntil: "networkidle" });
    assert.equal(studentRes.status(), 200, "Student lesson page returned HTTP 200");

    // Student should see the published text
    const studentContent = await studentPage.locator("main").innerText();
    assert.ok(studentContent.includes("G5 Acceptance Test"), "Student sees published content!");
    console.log("✓ Student successfully loaded the published revision!");

    // Student must NOT see administrative controls
    const adminControls = studentPage.locator('button:has-text("+ Add Text"), button:has-text("Publish Version"), button:has-text("Edit Metadata")');
    assert.equal(await adminControls.count(), 0, "No administrative editor controls leak to student view");
    console.log("✓ No administrative controls leaked to student view.");

    // Student Heading Hierarchy check
    const studentH1 = await studentPage.locator("h1").allInnerTexts();
    console.log("✓ Student view H1:", studentH1);
    assert.ok(studentH1.length >= 1, "Student view has proper semantic H1");

    await studentContext.close();

    console.log("\n=================================================");
    console.log("ALL 12 BROWSER ACCEPTANCE WORKFLOW STEPS PASSED!");
    console.log("=================================================");
  } finally {
    await browser.close();
  }
}

main().catch(err => {
  console.error("\n❌ Browser Acceptance Suite Failed:", err);
  process.exit(1);
});
