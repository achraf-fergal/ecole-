import { revalidatePath, revalidateTag } from "next/cache";
import { NextResponse } from "next/server";

export async function POST(request: Request) {
  try {
    const body = (await request.json().catch(() => ({}))) as { slug?: string };
    revalidateTag("courses", "max");
    revalidatePath("/courses");
    if (typeof body.slug === "string" && body.slug.trim()) {
      revalidatePath(`/courses/${encodeURIComponent(body.slug.trim())}`);
    }
    return NextResponse.json({ revalidated: true });
  } catch {
    return NextResponse.json({ revalidated: false }, { status: 500 });
  }
}
