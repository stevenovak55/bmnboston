# A/B Testing Guide for AI Chatbot Prompts

**Quick Start Guide for MLS Listings Display v6.9.0+**

---

## What is A/B Testing?

A/B testing (also called split testing) allows you to test different versions of your AI chatbot prompt to see which one performs better. Instead of guessing which prompt works best, you can use real data to make informed decisions.

**Example Scenario:**
- **Variant A (Control):** Professional, formal tone
- **Variant B (Test):** Friendly, conversational tone

The system automatically splits traffic between both variants (e.g., 50/50) and tracks which one gets better ratings and feedback from users.

---

## Why Use A/B Testing?

### Benefits

1. **Data-Driven Decisions**
   - No more guessing what works
   - See actual performance metrics
   - Make changes based on user feedback

2. **Continuous Improvement**
   - Iteratively improve your prompts
   - Test new ideas without risk
   - Learn what resonates with users

3. **Risk Mitigation**
   - Don't replace your entire prompt at once
   - Test changes with a small percentage of traffic
   - Roll back easily if performance drops

4. **Audience Understanding**
   - Learn what tone works best
   - Discover preferred response styles
   - Understand user expectations

### Real-World Impact

- **Higher user satisfaction:** Better prompts = better responses
- **Increased engagement:** Users interact more with good AI
- **Better conversions:** Quality responses lead to more inquiries
- **Reduced support load:** AI handles questions correctly first time

---

## Getting Started

### Step 1: Access A/B Testing Settings

1. Log into WordPress admin
2. Navigate to **MLS Display → AI Chatbot**
3. Click **AI Config** tab
4. Scroll to **A/B Testing** section

### Step 2: Enable A/B Testing

1. Toggle **Enable A/B Testing** to ON
2. You'll see your current prompt listed as "Control (Original)" at 100% weight
3. This is your baseline - don't delete it!

### Step 3: Create Your First Test Variant

1. Click **Add New Variant** button
2. Fill in the form:
   - **Variant Name:** Give it a descriptive name (e.g., "Friendly Tone")
   - **Prompt Content:** Paste your modified prompt
   - **Traffic Weight:** Start with 20% (keep Control at 80%)
3. Click **Save Variant**

### Step 4: Monitor Performance

1. Wait for conversations to accumulate (aim for 100+ uses)
2. Review the metrics in the variant table:
   - **Uses:** How many times this variant was used
   - **Avg Rating:** Average user rating (if ratings enabled)
   - **Feedback:** Positive vs. negative feedback counts
3. Compare variants side-by-side

### Step 5: Iterate

**If test variant performs better:**
1. Increase its weight to 50% or higher
2. Create a new test variant to compete against it
3. Eventually make the winner your new Control

**If test variant performs worse:**
1. Deactivate it (toggle switch)
2. Analyze why it didn't work
3. Create a new test variant with improvements

---

## Best Practices

### 1. Start Simple

**❌ Bad:** Test 5 variants at once
**✅ Good:** Test Control + 1 new variant

Start with just two variants to keep things manageable. Once you have a clear winner, make it your new Control and test against it.

### 2. Change One Thing at a Time

**❌ Bad:** Change tone, length, structure, and instructions all at once
**✅ Good:** Change only tone, keep everything else the same

This way you know exactly what caused the performance change.

**Example:**
```
Control: Professional tone, 200 words, bullet points
Variant: Friendly tone, 200 words, bullet points
         (only tone changed)
```

### 3. Wait for Statistical Significance

**❌ Bad:** Judge performance after 10 uses
**✅ Good:** Wait for at least 100 uses per variant

Small sample sizes can be misleading. A variant might look great with 5 uses, but regress to the mean with 100.

**Minimum Sample Sizes:**
- Low traffic: 50 uses per variant
- Medium traffic: 100 uses per variant
- High traffic: 250+ uses per variant

### 4. Set Appropriate Traffic Splits

**For New/Risky Changes:**
```
Control: 80%
Test:    20%
```
Test with small traffic first to limit exposure to potential issues.

**For Incremental Improvements:**
```
Control: 50%
Test:    50%
```
50/50 split gives you faster results if you're confident in your change.

**For Multiple Tests:**
```
Control:   40%
Variant A: 30%
Variant B: 30%
```
Compare multiple ideas simultaneously (advanced).

### 5. Monitor Multiple Metrics

Don't rely on just one metric. Look at:

- **Total Uses:** Is traffic splitting correctly?
- **Average Rating:** How do users rate responses?
- **Positive Feedback:** Are users happy with answers?
- **Negative Feedback:** Are users reporting issues?
- **Response Patterns:** Review actual conversations (in training data)

### 6. Document Your Tests

Keep notes on:
- What you tested and why
- What you expected to happen
- What actually happened
- Insights and learnings
- Next steps

**Example Test Log:**
```
Test #1: Professional vs. Friendly Tone
Date: Nov 24, 2025
Hypothesis: Friendly tone will increase user satisfaction
Control: Professional tone (80%)
Variant: Friendly, conversational tone (20%)
Results: After 150 uses, friendly tone showed:
  - 15% higher ratings (4.2 → 4.8)
  - 30% more positive feedback
  - Users asked follow-up questions more often
Decision: Increase friendly variant to 50%, create new test
Next: Test friendly tone + shorter responses
```

---

## Common Test Ideas

### Test 1: Tone Variations

**Control:** Professional and formal
```
I am a professional real estate assistant. I can help you search
for properties, analyze market data, and answer your questions
about the real estate market.
```

**Variant:** Friendly and conversational
```
Hi! I'm your friendly real estate assistant. I'd love to help you
find your perfect property or answer any questions you have about
the market. What can I help you with today?
```

**What to measure:** User engagement, response ratings, follow-up questions

---

### Test 2: Response Length

**Control:** Detailed explanations (300+ words)
```
I'll provide comprehensive information with detailed explanations,
examples, and context to ensure you have a complete understanding...
```

**Variant:** Concise responses (100-150 words)
```
I'll give you clear, concise answers. If you need more details,
just ask!
```

**What to measure:** User satisfaction, follow-up question rate, completion rate

---

### Test 3: Structure & Formatting

**Control:** Paragraph format
```
When searching for properties, I can help you filter by price,
location, bedrooms, bathrooms, and more. I can also provide
information about neighborhoods, market trends, and pricing...
```

**Variant:** Bullet points
```
I can help you with:
• Property searches (price, location, bedrooms, etc.)
• Neighborhood information
• Market trends and pricing data
• Answering specific questions
```

**What to measure:** Information retention, user satisfaction, clarity ratings

---

### Test 4: Personality & Brand Voice

**Control:** Generic assistant
```
I am an AI assistant that can help with real estate inquiries.
```

**Variant:** Brand-specific personality
```
I'm {business_name}'s AI assistant! With {years_in_business} years
of experience in {service_areas}, our team is here to help. I can
assist with property searches during our {business_hours}.
```

**What to measure:** Brand affinity, trust signals, conversion rate

---

### Test 5: Call-to-Action (CTA)

**Control:** Passive CTA
```
Feel free to contact us if you have more questions.
```

**Variant:** Active CTA
```
Ready to schedule a showing or talk to one of our agents?
Contact us at {contact_email} or call {contact_phone}!
```

**What to measure:** Contact form submissions, phone calls, email inquiries

---

## Interpreting Results

### Positive Signals

✅ **Higher average rating** (e.g., 4.2 → 4.7)
✅ **More positive feedback** (thumbs up, good ratings)
✅ **Fewer negative feedback** (complaints, corrections)
✅ **More follow-up questions** (indicates engagement)
✅ **Lower drop-off rate** (users complete conversations)

### Warning Signals

⚠️ **Lower average rating** than control
⚠️ **Increased negative feedback**
⚠️ **Users requesting clarification often**
⚠️ **Higher drop-off mid-conversation**
⚠️ **Users escalating to human agents more**

### What to Do

**If variant wins clearly:**
- Make it the new Control (100%)
- Create a new variant to test against it

**If variant loses clearly:**
- Deactivate it
- Analyze why it failed
- Test a different approach

**If results are inconclusive:**
- Continue testing with more data
- Check if external factors affected results
- Consider increasing traffic split for faster data

**If both perform equally:**
- Pick the simpler/easier to maintain version
- Test a more aggressive change next time

---

## Advanced Techniques

### Sequential Testing

Test prompts in sequence rather than simultaneously:

```
Week 1-2: Control (baseline measurement)
Week 3-4: Variant A (friendly tone)
Week 5-6: Variant B (concise format)
Week 7-8: Winner vs. new variant
```

**Pros:** Clean data, no traffic splitting needed
**Cons:** Takes longer, seasonal effects may skew results

### Multi-Armed Bandit

Automatically adjust traffic based on performance:

```
Start: Control 50%, Variant 50%
After 50 uses: Control 40%, Variant 60% (variant winning)
After 100 uses: Control 30%, Variant 70% (clear winner)
```

**Pros:** Maximizes good experiences, faster optimization
**Cons:** Requires custom implementation (not built-in yet)

### Cohort Analysis

Compare performance across user segments:

```
New users:      Variant wins (4.8 vs 4.2)
Returning users: Control wins (4.6 vs 4.1)
Insight: Different prompts for different audiences?
```

**Pros:** Deeper insights, personalization opportunities
**Cons:** Requires more data and analysis

---

## Troubleshooting

### Problem: Traffic not splitting correctly

**Symptoms:** One variant gets 90% of traffic despite 50% weight

**Solutions:**
1. Verify weights total 100%
2. Check that variants are active (toggle enabled)
3. Clear WordPress cache
4. Test with multiple conversations
5. Check browser isn't caching variant selection

### Problem: No performance difference

**Symptoms:** All variants have nearly identical metrics

**Solutions:**
1. Check if changes are actually different enough
2. Increase sample size (wait for more data)
3. Try more aggressive variations
4. Verify tracking is working (check `wp_mld_prompt_usage` table)

### Problem: All variants perform poorly

**Symptoms:** Ratings dropped across all variants vs. baseline

**Solutions:**
1. Check for external factors (AI model changes, data issues)
2. Review recent conversations for patterns
3. Test reverting to original prompt
4. Consider if user expectations changed

### Problem: Can't delete variant

**Error:** "Cannot delete variant currently in use"

**Solutions:**
1. Deactivate variant first (toggle off)
2. Wait for in-progress conversations to complete
3. Then delete

---

## Prompt Variables in A/B Testing

You can use prompt variables in any variant:

```
Control:
"I'm an assistant for {business_name}."

Variant:
"Hi! I'm {business_name}'s AI helper. We've been serving
{service_areas} for {years_in_business}. Our team of
{team_size} is available during {business_hours}."
```

**Benefits:**
- Keep business info consistent across variants
- Update once, applies to all variants
- Test messaging without hardcoding details

**Available Variables:**
- `{business_name}` - Your company name
- `{business_hours}` - Operating hours
- `{specialties}` - What you specialize in
- `{service_areas}` - Where you operate
- `{contact_phone}` - Phone number
- `{contact_email}` - Email address
- `{team_size}` - Team information
- `{years_in_business}` - Experience
- `{current_listings_count}` - Active listings
- `{price_range}` - Property price range
- `{site_url}` - Website URL

---

## Integration with Training Data

A/B testing works seamlessly with the training system:

1. **Conversations are tracked:** System logs which variant was used
2. **Add to training:** Save good/bad conversations as training examples
3. **Tag by variant:** Use tags like "variant-friendly-tone"
4. **Filter and analyze:** Use training filters to compare variants qualitatively
5. **Iterate:** Use insights to create better variants

**Workflow Example:**
```
1. Run A/B test (Variant A vs B)
2. Save notable conversations to training data
3. Filter training by tag: "variant-a"
4. Review qualitative feedback
5. Identify patterns (users love/hate X)
6. Create Variant C based on insights
7. Test Variant C vs winning variant
8. Repeat
```

---

## Checklist: Running Your First A/B Test

### Pre-Test (Planning)
- [ ] Define what you want to improve (tone? length? clarity?)
- [ ] Write hypothesis: "I believe [change] will result in [outcome]"
- [ ] Create test variant with ONE key difference
- [ ] Set success metrics (what defines "better"?)
- [ ] Plan minimum sample size (e.g., 100 uses)

### During Test (Execution)
- [ ] Enable A/B testing
- [ ] Set traffic split (recommend 80/20 for first test)
- [ ] Verify variants are active
- [ ] Test a few conversations yourself
- [ ] Monitor metrics daily
- [ ] Watch for technical issues

### Post-Test (Analysis)
- [ ] Wait for minimum sample size
- [ ] Compare metrics (rating, feedback, usage)
- [ ] Review sample conversations from each variant
- [ ] Determine winner based on data
- [ ] Document results and insights
- [ ] Plan next test based on learnings

---

## FAQ

**Q: How long should I run a test?**
A: Until you have at least 100 uses per variant. Could be days to weeks depending on traffic.

**Q: Can I edit a variant while it's running?**
A: Yes, but it will reset the performance metrics. Better to deactivate, edit, then reactivate as new variant.

**Q: What if I want to test 3+ variants?**
A: You can! Just ensure weights total 100%. But start with 2 variants until you're comfortable.

**Q: Do variants affect all AI providers (OpenAI, Claude, Gemini)?**
A: Yes, variants work across all AI providers. The system prompt is used regardless of which AI model you're using.

**Q: Can users tell they're in an A/B test?**
A: No, the experience is seamless. They just interact with the chatbot normally.

**Q: What happens if I disable A/B testing?**
A: System reverts to using your original system prompt (from AI Config settings).

**Q: Can I A/B test other things besides the prompt?**
A: Currently only prompts are supported. Future versions may include UI variations, response formats, etc.

**Q: How do I export A/B test data?**
A: Check the `wp_mld_prompt_usage` table in your database. You can export it for external analysis.

**Q: Does this work with cached conversations?**
A: Variant is selected per conversation. Caching doesn't affect variant selection.

---

## Resources

**Internal Documentation:**
- `.context/PLUGIN_LISTINGS_DISPLAY.md` - Technical architecture
- `CHANGELOG_v6.9.0.md` - Complete v6.9.0 changelog
- Training page - Review actual conversations

**External Resources:**
- [A/B Testing Guide (Optimizely)](https://www.optimizely.com/optimization-glossary/ab-testing/)
- [Statistical Significance Calculator](https://www.abtestguide.com/calc/)
- [Prompt Engineering Guide](https://www.promptingguide.ai/)

---

## Next Steps

1. **Try your first test:** Follow the step-by-step guide above
2. **Document results:** Keep notes on what works and what doesn't
3. **Iterate:** Use insights to continuously improve
4. **Share learnings:** Help your team understand what resonates with users
5. **Scale up:** Once comfortable, test more aggressively

**Remember:** A/B testing is a journey, not a destination. The goal is continuous improvement through data-driven decisions.

---

**Version:** 6.9.0
**Last Updated:** November 24, 2025
